<?php

namespace App\Domain\Exit\Services;

use App\Domain\Alumni\Models\AlumniProfile;
use App\Domain\Assets\Services\Assets;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exit management (§59): Resignation → Notice → Knowledge transfer → Manager / IT / Finance / Asset / HR
 * clearance → F&F → Documents → Exit interview → Alumni. Every step audited and on the timeline.
 */
final class Exits
{
    public function __construct(
        private readonly LifecycleEngine $lifecycle,
        private readonly Assets $assets,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
    ) {}

    public function initiate(Employee $employee, string $type, ?string $reason, CarbonInterface|string|null $resignationDate = null, CarbonInterface|string|null $lastWorkingDay = null, ?int $noticeDays = null, ?User $actor = null, array $extra = []): ExitCase
    {
        if (! array_key_exists($type, config('peopleos.exit.types'))) {
            throw new RuntimeException('Unknown exit type.');
        }
        if (ExitCase::query()->where('employee_id', $employee->id)->whereIn('status', ExitCase::OPEN)->exists()) {
            throw new RuntimeException('An exit is already in progress for this employee.');
        }
        if (! $employee->lifecycle_state->isEmployed() || $employee->lifecycle_state === LifecycleState::NoticePeriod) {
            throw new RuntimeException('Only current employees can be exited.');
        }

        $immediate = in_array($type, config('peopleos.exit.immediate_types', []), true);
        $resignation = $resignationDate ? Carbon::parse($resignationDate)->startOfDay() : now()->startOfDay();
        $noticeDays = $immediate ? 0 : ($noticeDays ?? (int) $this->settings->get('exit.notice_days', 30));
        $noticeEnd = $resignation->copy()->addDays($noticeDays);
        $lwd = $lastWorkingDay ? Carbon::parse($lastWorkingDay)->startOfDay() : ($immediate ? now()->startOfDay() : $noticeEnd);

        if ($lwd->lt($resignation)) {
            throw new RuntimeException('The last working day cannot be before the resignation date.');
        }

        return DB::transaction(function () use ($employee, $type, $reason, $resignation, $noticeDays, $noticeEnd, $lwd, $actor, $extra, $immediate) {
            $employee->loadMissing('currentManager');
            $case = ExitCase::create([
                'number' => $this->nextNumber(),
                'employee_id' => $employee->id,
                'type' => $type,
                'reason' => $reason,
                'status' => 'notice',
                'initiated_by' => $actor?->id ?? auth()->id(),
                'initiated_on' => now(),
                'resignation_date' => $resignation,
                'notice_days' => $noticeDays,
                'notice_end_date' => $noticeEnd,
                'last_working_day' => $lwd,
                'manager_id' => $employee->currentManager?->manager_id,
                'knowledge_transfer_to' => $extra['knowledge_transfer_to'] ?? null,
                'knowledge_transfer_notes' => $extra['knowledge_transfer_notes'] ?? null,
                'is_rehire_eligible' => $extra['is_rehire_eligible'] ?? ! in_array($type, ['termination', 'absconding'], true),
                'notes' => $extra['notes'] ?? null,
            ]);

            $this->buildClearances($case, $employee);

            $this->lifecycle->transition($employee, LifecycleState::NoticePeriod, now(), $reason ?? config("peopleos.exit.types.{$type}"), ['exit_case_id' => $case->id, 'type' => $type]);
            $employee->update(['exit_date' => $lwd]);

            $this->timeline->record($employee, 'exit', config("peopleos.exit.types.{$type}").' initiated', now(), $reason, $case, ['last_working_day' => $lwd->toDateString()]);
            ExitEvent::dispatch('exit.initiated', $employee, $case, ['number' => $case->number, 'type' => config("peopleos.exit.types.{$type}"), 'last_working_day' => $lwd->toDateString()], array_filter([$case->manager()->value('user_id')]));

            if ($immediate || $lwd->lte(now()->addDays((int) $this->settings->get('exit.clearance_lead_days', 7)))) {
                $this->startClearance($case->refresh(), $actor);
            }

            return $case->refresh();
        });
    }

    /** Employee self-service resignation: type is fixed and the employee cannot pick their own last day beyond notice. */
    public function resign(Employee $employee, string $reason, CarbonInterface|string|null $requestedLastDay = null, ?User $actor = null): ExitCase
    {
        $notice = (int) $this->settings->get('exit.notice_days', 30);
        $requested = $requestedLastDay ? Carbon::parse($requestedLastDay)->startOfDay() : null;
        $lwd = $requested && $requested->lt(now()->addDays($notice)) ? $requested : now()->addDays($notice);

        return $this->initiate($employee, 'resignation', $reason, now(), $lwd, $notice, $actor);
    }

    private function buildClearances(ExitCase $case, Employee $employee): void
    {
        $roleFor = fn (string $setting) => Role::query()->where('slug', $this->settings->get($setting))->value('id');
        $assets = $this->assets->clearanceFor($employee);
        $i = 0;

        foreach (config('peopleos.exit.clearance_stages', []) as $stage => $definition) {
            $items = collect($definition['items'])->map(fn ($item) => ['item' => $item, 'done' => false])->all();
            $owner = null;
            $role = null;

            switch ($stage) {
                case 'manager':
                    $owner = $case->manager_id ? Employee::query()->whereKey($case->manager_id)->value('user_id') : null;
                    break;
                case 'it':
                    $role = $roleFor('exit.it_clearance_role');
                    break;
                case 'finance':
                    $role = $roleFor('exit.finance_clearance_role');
                    break;
                case 'asset':
                    $role = $roleFor('exit.it_clearance_role');
                    $items = $assets->map(fn ($a) => ['item' => "Return {$a->asset->name} [{$a->asset->asset_tag}]", 'done' => false])->values()->all() ?: $items;
                    break;
                case 'hr':
                    $role = $roleFor('exit.hr_clearance_role');
                    break;
            }

            ExitClearance::create(['exit_case_id' => $case->id, 'stage' => $stage, 'name' => $definition['name'], 'owner_user_id' => $owner, 'owner_role_id' => $role, 'items' => $items, 'status' => 'pending', 'sort_order' => ++$i * 10]);
        }
    }

    public function startClearance(ExitCase $case, ?User $actor = null): ExitCase
    {
        if (! in_array($case->status, ['initiated', 'notice'], true)) {
            return $case;
        }

        $case->update(['status' => 'clearance', 'clearance_started_at' => now()]);
        $this->audit->record(AuditAction::Update, 'exit', $case, [['field' => 'status', 'before' => 'notice', 'after' => 'clearance']], null, actor: $actor);

        foreach ($case->clearances()->with('ownerRole')->get() as $clearance) {
            $recipients = array_filter([$clearance->owner_user_id, ...($clearance->ownerRole?->users()->pluck('users.id')->all() ?? [])]);
            ExitEvent::dispatch('exit.clearance.pending', $case->employee, $clearance, ['number' => $case->number, 'stage' => $clearance->name, 'last_working_day' => $case->last_working_day->toDateString()], array_values(array_unique($recipients)));
        }

        return $case;
    }

    /** @param  array<int, bool>|null  $itemsDone  index => done */
    public function clearStage(ExitClearance $clearance, User $actor, ?string $remarks = null, float $recoverable = 0, ?array $itemsDone = null): ExitClearance
    {
        $case = $clearance->exitCase()->with('employee')->firstOrFail();
        if ($case->status !== 'clearance') {
            throw new RuntimeException('Clearance has not started for this exit.');
        }
        if ($clearance->status === 'cleared') {
            throw new RuntimeException('This stage is already cleared.');
        }

        if ($clearance->stage === 'asset') {
            $inCustody = $this->assets->clearanceFor($case->employee);
            if ($inCustody->isNotEmpty() && $recoverable <= 0) {
                throw new RuntimeException('Assets still in custody: '.$inCustody->map(fn ($a) => $a->asset->asset_tag)->implode(', ').'. Take them back, or record a recoverable amount.');
            }
        }

        $items = collect($clearance->items ?? [])->map(fn ($item, $i) => ['item' => $item['item'], 'done' => $itemsDone === null ? true : (bool) ($itemsDone[$i] ?? false)])->all();

        return DB::transaction(function () use ($clearance, $case, $actor, $remarks, $recoverable, $items) {
            $clearance->update(['status' => 'cleared', 'remarks' => $remarks, 'recoverable_amount' => round($recoverable, 2), 'items' => $items, 'cleared_by' => $actor->id, 'cleared_at' => now()]);
            $this->audit->record(AuditAction::Approved, 'exit', $clearance, [['field' => 'status', 'before' => 'pending', 'after' => 'cleared']], $remarks, actor: $actor, metadata: ['exit_case' => $case->number, 'recoverable' => $recoverable]);
            ExitEvent::dispatch('exit.clearance.cleared', $case->employee, $clearance, ['number' => $case->number, 'stage' => $clearance->name], array_filter([$case->initiated_by]));

            if ($case->refresh()->allCleared()) {
                $case->update(['status' => 'settlement']);
                app(FinalSettlements::class)->ensure($case);
            }

            return $clearance->refresh();
        });
    }

    public function blockStage(ExitClearance $clearance, User $actor, string $remarks): ExitClearance
    {
        $clearance->update(['status' => 'blocked', 'remarks' => $remarks]);
        $case = $clearance->exitCase()->with('employee')->firstOrFail();
        $this->audit->record(AuditAction::Rejected, 'exit', $clearance, [['field' => 'status', 'before' => 'pending', 'after' => 'blocked']], $remarks, actor: $actor);
        ExitEvent::dispatch('exit.clearance.blocked', $case->employee, $clearance, ['number' => $case->number, 'stage' => $clearance->name, 'remarks' => $remarks], array_filter([$case->initiated_by, $case->employee->user_id]));

        return $clearance;
    }

    public function markNotApplicable(ExitClearance $clearance, User $actor, string $remarks): ExitClearance
    {
        $clearance->update(['status' => 'na', 'remarks' => $remarks, 'cleared_by' => $actor->id, 'cleared_at' => now()]);
        $case = $clearance->exitCase()->firstOrFail();
        if ($case->refresh()->allCleared() && $case->status === 'clearance') {
            $case->update(['status' => 'settlement']);
            app(FinalSettlements::class)->ensure($case);
        }

        return $clearance;
    }

    public function withdraw(ExitCase $case, string $reason, ?User $actor = null): ExitCase
    {
        if ($case->type !== 'resignation' || ! $case->isOpen()) {
            throw new RuntimeException('Only a resignation that is still open can be withdrawn.');
        }

        return DB::transaction(function () use ($case, $reason) {
            $employee = $case->employee()->firstOrFail();
            $case->withAuditReason($reason)->update(['status' => 'withdrawn']);
            $this->lifecycle->transition($employee, LifecycleState::Active, now(), 'Resignation withdrawn: '.$reason, ['exit_case_id' => $case->id]);
            $employee->update(['exit_date' => null]);
            $this->timeline->record($employee, 'exit', 'Resignation withdrawn', now(), $reason, $case);
            ExitEvent::dispatch('exit.withdrawn', $employee, $case, ['number' => $case->number], array_filter([$case->manager()->value('user_id'), $case->initiated_by]));

            return $case;
        });
    }

    public function complete(ExitCase $case, ?User $actor = null, bool $requireSettlement = true): ExitCase
    {
        $case->loadMissing(['clearances', 'settlement', 'employee.user']);
        if (! $case->isOpen()) {
            throw new RuntimeException('The exit is not open.');
        }
        if (! $case->allCleared()) {
            throw new RuntimeException('All clearance stages must be cleared first.');
        }
        if ($requireSettlement && ! in_array($case->settlement?->status, ['approved', 'paid'], true)) {
            throw new RuntimeException('The full & final settlement must be approved first.');
        }

        return DB::transaction(function () use ($case, $actor) {
            $employee = $case->employee;
            $this->lifecycle->transition($employee, LifecycleState::Exited, $case->last_working_day, config("peopleos.exit.types.{$case->type}"), ['exit_case_id' => $case->id]);
            $case->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $actor?->id ?? auth()->id()]);

            if ($employee->user) {
                $employee->user->forceFill(['status' => self::inactiveStatus()])->save();
            }

            $this->timeline->record($employee, 'exit', 'Exit completed', $case->last_working_day, null, $case);
            ExitEvent::dispatch('exit.completed', $employee, $case, ['number' => $case->number, 'last_working_day' => $case->last_working_day->toDateString()], array_filter([$case->manager()->value('user_id')]));

            return $case->refresh();
        });
    }

    /** Turns an exited employee into an alumnus: profile + portal access limited to the Alumni role. */
    public function createAlumni(ExitCase $case, ?User $actor = null, array $profile = []): AlumniProfile
    {
        $employee = $case->employee()->with(['user', 'person', 'positions.designation', 'positions.department'])->firstOrFail();
        if ($case->status !== 'completed') {
            throw new RuntimeException('Complete the exit before creating the alumni profile.');
        }
        if ($employee->lifecycle_state !== LifecycleState::Alumni) {
            $this->lifecycle->transition($employee, LifecycleState::Alumni, now(), 'Alumni created', ['exit_case_id' => $case->id]);
        }

        $lastPosition = $employee->positions->sortByDesc('effective_from')->first();
        $alumni = AlumniProfile::query()->updateOrCreate(['employee_id' => $employee->id], array_merge([
            'exit_case_id' => $case->id,
            'personal_email' => $employee->person?->personal_email,
            'phone' => $employee->person?->mobile ?? null,
            'last_designation' => $lastPosition?->designation?->name,
            'last_department' => $lastPosition?->department?->name,
            'joined_on' => $employee->joining_date,
            'exited_on' => $case->last_working_day,
            'exit_type' => $case->type,
            'is_rehire_eligible' => (bool) ($case->is_rehire_eligible ?? true),
            'portal_enabled' => true,
            'consent_to_contact' => true,
        ], $profile));

        if ($employee->user && $alumni->portal_enabled) {
            $role = Role::query()->where('slug', 'alumni')->first();
            if ($role) {
                $employee->user->roles()->sync([$role->id]);
            }
            $employee->user->forceFill(['status' => UserStatus::Active])->save();
        }

        $case->update(['alumni_created_at' => now()]);
        $this->timeline->record($employee, 'exit', 'Alumni profile created', now(), null, $alumni);
        ExitEvent::dispatch('exit.alumni_created', $employee, $alumni, ['number' => $case->number], array_filter([$employee->user_id]));

        return $alumni;
    }

    /** Daily: start clearance for exits whose last working day is within the lead window. */
    public function tick(): int
    {
        $lead = (int) $this->settings->get('exit.clearance_lead_days', 7);
        $started = 0;

        ExitCase::query()->with('employee')->whereIn('status', ['initiated', 'notice'])->whereDate('last_working_day', '<=', now()->addDays($lead))->get()
            ->each(function (ExitCase $case) use (&$started) {
                $this->startClearance($case);
                $started++;
            });

        return $started;
    }

    public function nextNumber(): string
    {
        $year = now()->format('Y');
        $last = ExitCase::query()->where('number', 'like', "EXIT-{$year}-%")->orderByDesc('id')->value('number');

        return sprintf('EXIT-%s-%05d', $year, $last ? ((int) substr($last, -5)) + 1 : 1);
    }

    private static function inactiveStatus(): UserStatus
    {
        foreach (UserStatus::cases() as $case) {
            if (in_array($case->value, ['inactive', 'disabled', 'deactivated', 'suspended'], true)) {
                return $case;
            }
        }

        return UserStatus::cases()[array_key_last(UserStatus::cases())];
    }
}
