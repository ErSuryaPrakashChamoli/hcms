<?php

namespace App\Domain\Experience\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Experience\Contracts\BulkOperation;
use App\Domain\Experience\Jobs\RunBulkOperation;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * HROne parity WO-1: bulk runs on registers ("filter, select, act").
 *
 * start() takes the records the person chose — the rows selected, or every record matching the register's filters —
 * checks the run itself (bulk.run and the operation's permission, at least one and at most `bulk.max_records` records,
 * the typed count above `bulk.confirm_over`), records the run and queues it. execute() (the queued job) runs as the
 * person who started it, so their access scope narrows every query and the owning services see them as the actor, and
 * acts on each record on its own:
 * - a record no longer reachable (outside the scope, or gone) is refused;
 * - so is one the person may not `view`, one outside their scope, one the operation refuses, and one its owning
 *   service refuses (the service's own message);
 * - each record runs in its own transaction, so a refusal leaves that record unchanged and the run goes on.
 * Every audit event of the run shares one operation id and the run closes with a BULK_OPERATION summary. The person
 * gets a notification with the result; the run's page lists every record's outcome.
 */
final class BulkRuns
{
    public function __construct(
        private readonly BulkOperations $operations,
        private readonly AuditRecorder $audit,
        private readonly AccessScopes $scopes,
    ) {}

    public static function confirmOver(): int
    {
        return max(1, (int) config('peopleos.bulk.confirm_over', 25));
    }

    public static function maxRecords(): int
    {
        return max(1, (int) config('peopleos.bulk.max_records', 5000));
    }

    /** Does a run of this many records need the typed-count confirmation? */
    public static function needsTypedCount(int $count): bool
    {
        return $count > self::confirmOver();
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array<string, mixed>  $data
     */
    public function start(User $actor, string $register, string $operationKey, array $ids, array $data = [], string $selection = 'selected', ?int $confirmedCount = null): BulkRun
    {
        $operation = $this->operations->get($operationKey);
        if (! $this->operations->allows($actor, $operation)) {
            throw new RuntimeException('You cannot run "'.$operation->label().'" in bulk.');
        }
        if (! array_key_exists($selection, BulkRun::SELECTIONS)) {
            throw new RuntimeException('Unknown selection.');
        }
        if (preg_match('/^[a-z0-9_.-]{1,64}$/', $register) !== 1) {
            throw new RuntimeException('Unknown register.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
        if ($ids === []) {
            throw new RuntimeException('Choose at least one record.');
        }
        if (count($ids) > self::maxRecords()) {
            throw new RuntimeException('A bulk run takes at most '.self::maxRecords().' records; narrow the filters and run it again.');
        }
        if (self::needsTypedCount(count($ids)) && $confirmedCount !== count($ids)) {
            throw new RuntimeException('Type the number of records ('.count($ids).') to confirm this bulk run.');
        }
        $input = $operation->prepare($data, $actor);

        $run = BulkRun::create([
            'user_id' => $actor->id,
            'register' => $register,
            'operation' => $operation->key(),
            'operation_label' => mb_substr($operation->label(), 0, 160),
            'selection' => $selection,
            'record_ids' => $ids,
            'input' => $input === [] ? null : $input,
            'total' => count($ids),
            'status' => 'queued',
        ]);

        RunBulkOperation::dispatch($run->id);

        return $run->refresh();
    }

    /** The queued part: act on each record as the person who started the run. Safe to retry (records already reached are skipped). */
    public function execute(BulkRun $run): BulkRun
    {
        if ($run->isFinished()) {
            return $run;
        }
        $operation = $this->operations->find($run->operation);
        $actor = User::query()->find($run->user_id);
        $refusal = match (true) {
            $operation === null => 'This bulk action is no longer available.',
            $actor === null || (int) $actor->tenant_id !== (int) $run->tenant_id || $actor->status !== UserStatus::Active => 'The person who started this run can no longer act.',
            ! $this->operations->allows($actor, $operation) => 'The person who started this run no longer holds the permission for it.',
            default => null,
        };
        if ($refusal !== null) {
            $run->update(['status' => 'failed', 'error' => $refusal, 'finished_at' => now()]);
            $actor !== null && $this->notify($actor, $run);

            return $run;
        }

        $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);
        $input = (array) ($run->input ?? []);

        ScreenAccess::as($actor, function () use ($run, $operation, $actor, $input) {
            $this->audit->operation('experience', 'Bulk: '.$run->operation_label, function (string $operationId) use ($run, $operation, $actor, $input) {
                if ($run->operation_id === null) {
                    $run->update(['operation_id' => $operationId]);
                }
                $reached = BulkRunItem::query()->where('bulk_run_id', $run->id)->pluck('record_id')->map(fn ($id) => (int) $id)->flip();
                $pending = array_values(array_filter((array) $run->record_ids, fn ($id) => ! isset($reached[(int) $id])));
                $model = $operation->model();
                foreach (array_chunk($pending, max(1, (int) config('peopleos.bulk.chunk', 100))) as $chunk) {
                    // The person's access scope narrows this query: a record outside it does not come back.
                    $records = $model::query()->with($operation->with())->whereKey($chunk)->get()->keyBy(fn (Model $m) => (int) $m->getKey());
                    foreach ($chunk as $id) {
                        $this->actOn($run, $operation, $records->get((int) $id), (int) $id, $input, $actor);
                    }
                }
                $counts = $this->counts($run);

                return ['succeeded' => $counts['done'], 'failed' => $counts['refused'] + $counts['failed'],
                    'ids' => BulkRunItem::query()->where('bulk_run_id', $run->id)->where('outcome', 'done')->orderBy('id')->limit(1000)->pluck('record_id')->all()];
            }, entityType: $operation->model());
        });

        $counts = $this->counts($run);
        $run->update($counts + ['status' => 'completed', 'finished_at' => now()]);
        $this->notify($actor, $run);

        return $run->refresh();
    }

    /** @param  array<string, mixed>  $input */
    private function actOn(BulkRun $run, BulkOperation $operation, ?Model $record, int $id, array $input, User $actor): void
    {
        if ($record === null) {
            $this->item($run, $id, null, 'refused', 'Not in your access, or no longer exists.');

            return;
        }
        $label = rescue(fn () => $operation->recordLabel($record), class_basename($record).' #'.$id, false);
        $refusal = $this->refusal($operation, $record, $actor);
        if ($refusal !== null) {
            $this->item($run, $id, $label, 'refused', $refusal);

            return;
        }
        try {
            $message = DB::transaction(fn () => $operation->handle($record, $input, $actor));
            $this->item($run, $id, $label, 'done', $message);
        } catch (QueryException $e) {
            report($e);
            $this->item($run, $id, $label, 'failed', 'Could not be saved; the record was left unchanged.');
        } catch (RuntimeException|LogicException|ValidationException|AuthorizationException $e) {
            $this->item($run, $id, $label, 'refused', $e->getMessage() !== '' ? $e->getMessage() : 'Refused.');
        } catch (Throwable $e) {
            report($e);
            $this->item($run, $id, $label, 'failed', 'Unexpected error; the record was left unchanged.');
        }
    }

    /** The same questions for every operation, then the operation's own. */
    private function refusal(BulkOperation $operation, Model $record, User $actor): ?string
    {
        if (! $actor->hasPermission($operation->permission())) {
            return 'You do not hold the permission for this action.';
        }
        if (Gate::getPolicyFor($record) !== null && Gate::forUser($actor)->denies('view', $record)) {
            return 'You cannot see this record.';
        }
        if (! $this->scopes->allows($actor, $record)) {
            return 'Outside your access scope.';
        }

        return $operation->refusal($record, $actor);
    }

    private function item(BulkRun $run, int $id, ?string $label, string $outcome, ?string $message): void
    {
        BulkRunItem::query()->create([
            'bulk_run_id' => $run->id,
            'record_id' => $id,
            'label' => $label === null ? null : mb_substr($label, 0, 191),
            'outcome' => $outcome,
            'message' => $message === null ? null : mb_substr($message, 0, 500),
        ]);
    }

    /** @return array{done: int, refused: int, failed: int} */
    private function counts(BulkRun $run): array
    {
        $counts = BulkRunItem::query()->where('bulk_run_id', $run->id)->selectRaw('outcome, count(*) as aggregate')->groupBy('outcome')->toBase()->pluck('aggregate', 'outcome');

        return ['done' => (int) ($counts['done'] ?? 0), 'refused' => (int) ($counts['refused'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0)];
    }

    private function notify(User $actor, BulkRun $run): void
    {
        rescue(function () use ($actor, $run) {
            $notification = Notification::make()
                ->title($run->status === 'failed' ? 'Bulk run not done: '.$run->operation_label : 'Bulk run finished: '.$run->operation_label)
                ->body($run->status === 'failed' ? (string) $run->error : $run->summary().' (of '.$run->total.')')
                ->icon($run->status === 'failed' || $run->refused + $run->failed > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->iconColor($run->status === 'failed' ? 'danger' : ($run->refused + $run->failed > 0 ? 'warning' : 'success'))
                ->actions([Action::make('open')->label('See each record')->url(BulkRunResource::getUrl('view', ['record' => $run]))]);
            $actor->notifyNow($notification->toDatabase());
        }, report: true);
    }
}
