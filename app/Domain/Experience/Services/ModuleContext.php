<?php

namespace App\Domain\Experience\Services;

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Letters\Models\Letter;
use App\Filament\Pages\Approvals;
use BackedEnum;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * UX.15 closure: what matters on a module list, in words. Given the page's own table query (the query the
 * table renders, with the resource's scopes and every modifyQueryUsing constraint already in it), it counts
 * what the viewer can see: total, status breakdown in business language, the effective-dated split, what
 * changed this week, the people involved, and decisions waiting for the viewer when the model feeds the
 * Approval Center. Counts only: it never reads a record's other fields, so it can show nothing the table
 * could not.
 */
final class ModuleContext
{
    /** Status phrases: [label for a chip, phrase for the sentence, tone, attention order (lower first)]. */
    public const STATUSES = [
        'pending' => ['Waiting for a decision', 'waiting for a decision', 'warning', 0],
        'pending_approval' => ['Waiting for approval', 'waiting for approval', 'warning', 0],
        'submitted' => ['Submitted', 'submitted', 'warning', 1],
        'under_review' => ['Under review', 'under review', 'warning', 1],
        'cancel_requested' => ['Cancellation requested', 'asking to cancel', 'warning', 1],
        'escalated' => ['Escalated', 'escalated', 'danger', 0],
        'failed' => ['Failed', 'failed', 'danger', 0],
        'overdue' => ['Overdue', 'overdue', 'danger', 0],
        'open' => ['Open', 'open', 'info', 2],
        'new' => ['New', 'new', 'info', 2],
        'acknowledged' => ['Acknowledged', 'acknowledged', 'info', 2],
        'in_progress' => ['In progress', 'in progress', 'info', 2],
        'waiting_employee' => ['Waiting for the employee', 'waiting for the employee', 'info', 2],
        'draft' => ['Draft', 'in draft', 'neutral', 3],
        'scheduled' => ['Scheduled', 'scheduled', 'info', 3],
        'active' => ['Active', 'active', 'success', 4],
        'approved' => ['Approved', 'approved', 'success', 4],
        'published' => ['Published', 'published', 'success', 4],
        'completed' => ['Completed', 'completed', 'success', 5],
        'resolved' => ['Resolved', 'resolved', 'success', 5],
        'closed' => ['Closed', 'closed', 'neutral', 5],
        'finalized' => ['Finalised', 'finalised', 'success', 5],
        'paid' => ['Paid', 'paid', 'success', 5],
        'inactive' => ['Inactive', 'inactive', 'neutral', 6],
        'rejected' => ['Rejected', 'rejected', 'neutral', 6],
        'cancelled' => ['Cancelled', 'cancelled', 'neutral', 6],
        'withdrawn' => ['Withdrawn', 'withdrawn', 'neutral', 6],
        'archived' => ['Archived', 'archived', 'neutral', 7],
        'expired' => ['Expired', 'expired', 'neutral', 7],
    ];

    /** Models whose pending rows are decided in the Approval Center (model => ApprovalItem types). */
    private const APPROVAL_SOURCES = [
        LeaveRequest::class => ['leave', 'leave_cancellation'],
        AttendanceRegularisation::class => ['regularisation'],
        CompensationChange::class => ['compensation'],
        Letter::class => ['letter'],
    ];

    /** @var array<string, array<int, string>> table => columns (per request) */
    private array $columns = [];

    public function __construct(private readonly ApprovalCenter $approvals) {}

    /**
     * @param  Builder<Model>  $query  the page's table query (scopes applied), without any lens
     * @return array{total: int, sentence: string, lenses: list<array{key: string, label: string, count: int, tone: string}>, facts: list<string>, approvals: ?array{count: int, url: string}, status: bool}
     */
    public function forList(Builder $query, string $singular, string $plural, ?User $viewer): array
    {
        $model = $query->getModel();
        $table = $model->getTable();
        $has = fn (string $column) => in_array($column, $this->columnsOf($table), true);
        $base = $query->clone()->reorder();

        $total = $this->safe(fn () => (int) $base->clone()->toBase()->getCountForPagination(), 0);

        $lenses = [];
        // The queries below run whenever their column exists, whatever the data, so a list's query count never depends on volume.
        if ($has('status')) {
            $rows = $this->safe(fn () => $base->clone()->toBase()->reorder()->select($model->qualifyColumn('status').' as pos_status')->selectRaw('count(*) as pos_n')
                ->groupBy($model->qualifyColumn('status'))->pluck('pos_n', 'pos_status')->all(), []);
            foreach ($rows as $status => $count) {
                $key = $status instanceof BackedEnum ? (string) $status->value : (string) $status;
                if ($key === '') {
                    continue;
                }
                [$label, , $tone, $order] = self::STATUSES[$key] ?? [$this->enumLabel($model, $key) ?? Str::ucfirst(str_replace('_', ' ', $key)), null, 'neutral', 8];
                $lenses[] = ['key' => $key, 'label' => $label, 'count' => (int) $count, 'tone' => $tone, 'order' => $order];
            }
            usort($lenses, fn ($a, $b) => [$a['order'], -$a['count']] <=> [$b['order'], -$b['count']]);
        }

        $facts = [];
        $parts = [];
        foreach (array_slice(array_values(array_filter($lenses, fn ($l) => ($l['order'] ?? 8) <= 2)), 0, 2) as $l) {
            $parts[] = $l['count'].' '.(self::STATUSES[$l['key']][1] ?? mb_strtolower($l['label']));
        }
        if ($has('effective_from') && $has('effective_to')) {
            $today = now()->toDateString();
            $later = $this->safe(fn () => (int) $base->clone()->toBase()->where($model->qualifyColumn('effective_from'), '>', $today)->count(), 0);
            $ended = $this->safe(fn () => (int) $base->clone()->toBase()->where($model->qualifyColumn('effective_to'), '<', $today)->count(), 0);
            $now = max(0, $total - $later - $ended);
            if ($total > 0) {
                $facts[] = $now.' in effect';
            }
            if ($later > 0) {
                $facts[] = $later.' '.($later === 1 ? 'starts' : 'start').' later';
            }
            if ($ended > 0) {
                $facts[] = $ended.' ended';
            }
        }
        if ($has('updated_at')) {
            $changed = $this->safe(fn () => (int) $base->clone()->toBase()->where($model->qualifyColumn('updated_at'), '>=', now()->subDays(7))->count(), 0);
            if ($changed > 0) {
                $facts[] = $changed.' changed this week';
            }
        }
        if ($has('employee_id')) {
            $people = $this->safe(fn () => (int) $base->clone()->toBase()->distinct()->count($model->qualifyColumn('employee_id')), 0);
            if ($people > 0 && $people < $total) {
                $facts[] = 'for '.number_format($people).' '.($people === 1 ? 'person' : 'people');
            }
        }

        $approvals = null;
        if ($viewer !== null && isset(self::APPROVAL_SOURCES[$model::class]) && $this->safe(fn () => Approvals::canAccess(), false)) {
            $types = self::APPROVAL_SOURCES[$model::class];
            $count = $this->safe(fn () => $this->approvals->pending($viewer)->filter(fn ($i) => in_array($i->type, $types, true))->count(), 0);
            if ($count > 0) {
                $approvals = ['count' => $count, 'url' => Approvals::getUrl()];
            }
        }

        $what = number_format($total).' '.($total === 1 ? $singular : $plural);
        $sentence = $total === 0 ? 'Nothing here yet that you can see.' : ucfirst(implode(' · ', [$what.' you can see', ...$parts, ...array_slice($facts, 0, 3)])).'.';

        return ['total' => $total, 'sentence' => $sentence, 'lenses' => array_map(fn ($l) => array_diff_key($l, ['order' => 0]), $lenses),
            'facts' => $facts, 'approvals' => $approvals, 'status' => $has('status')];
    }

    /** True when the table has a status column (the lens may filter on it). */
    public function hasStatus(Model $model): bool
    {
        return in_array('status', $this->columnsOf($model->getTable()), true);
    }

    /** @return array<int, string> */
    private function columnsOf(string $table): array
    {
        return $this->columns[$table] ??= $this->safe(fn () => Schema::getColumnListing($table), []);
    }

    private function enumLabel(Model $model, string $value): ?string
    {
        $cast = $model->getCasts()['status'] ?? null;
        if (is_string($cast) && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
            $case = $cast::tryFrom($value);

            return $case instanceof HasLabel ? (string) $case->getLabel() : null;
        }

        return null;
    }

    private function safe(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
