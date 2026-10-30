<?php

namespace App\Domain\Exit\Services;

use App\Domain\Alumni\Models\AlumniProfile;
use App\Domain\Assets\Services\Assets;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\WorkforceReason;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Exit\Models\ClearanceChecklist;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Support\PolicyLetters;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Numbering\NumberSeries;
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
        // HROne setup parity: the exit policy limits how far HR or a manager may backdate, and sets the notice.
        $policy = app(PolicyResolver::class)->resolve('exit', $employee, now());
        $hrBack = $policy?->setting('hr_backdate_days');
        if (is_numeric($hrBack) && $resignation->lt(now()->startOfDay()->subDays((int) $hrBack))) {
            throw new RuntimeException("A resignation can be backdated by at most {$hrBack} day(s).");
        }
        [$separationReasonId, $rehireBlockId] = $this->reasons($extra, $reason);
        $noticeDays = $immediate ? 0 : ($noticeDays ?? $this->noticeDays($employee));
        $noticeEnd = $resignation->copy()->addDays($noticeDays);
        $lwd = $lastWorkingDay ? Carbon::parse($lastWorkingDay)->startOfDay() : ($immediate ? now()->startOfDay() : $noticeEnd);

        if ($lwd->lt($resignation)) {
            throw new RuntimeException('The last working day cannot be before the resignation date.');
        }

        $company = NumberSeries::companyOf($employee);
        app(NumberSeries::class)->prepare('exit_case', $company);

        return DB::transaction(function () use ($employee, $type, $reason, $resignation, $noticeDays, $noticeEnd, $lwd, $actor, $extra, $immediate, $company, $separationReasonId, $rehireBlockId) {
            $employee->loadMissing('currentManager');
            $case = ExitCase::create([
                'number' => $this->nextNumber($company),
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
                'workforce_reason_id' => $separationReasonId,
                'rehire_block_reason_id' => $rehireBlockId,
                'notes' => $extra['notes'] ?? null,
            ]);

            $this->buildClearances($case, $employee);
            // HROne setup parity P13: the separation policy's exit interviews (employee, manager, HR) as feedback tasks.
            app(FeedbackRequests::class)->raiseForExit($case, $employee);
            PolicyLetters::draft(app(PolicyResolver::class)->resolve('exit', $employee, now())?->setting('letter_on_exit'), $employee, $actor, $case);

            $this->lifecycle->transition($employee, LifecycleState::NoticePeriod, now(), $reason ?? config("peopleos.exit.types.{$type}"), ['exit_case_id' => $case->id, 'type' => $type]);
            $employee->update(['exit_date' => $lwd]);

            // Phase 14: the free-text reason stays on the exit case (exit.view); the timeline carries the fact only.
            $this->timeline->record($employee, 'exit', config("peopleos.exit.types.{$type}").' initiated', now(), null, $case, ['last_working_day' => $lwd->toDateString()]);
            ExitEvent::dispatch('exit.initiated', $employee, $case, ['number' => $case->number, 'type' => config("peopleos.exit.types.{$type}"), 'last_working_day' => $lwd->toDateString()], array_filter([$case->manager()->value('user_id')]));

            if ($immediate || $lwd->lte(now()->addDays((int) $this->settings->get('exit.clearance_lead_days', 7)))) {
                $this->startClearance($case->refresh(), $actor);
            }

            return $case->refresh();
        });
    }

    /**
     * Employee self-service resignation: type is fixed and the employee cannot pick their own last day beyond notice.
     * HROne setup parity: the exit policy may let the employee backdate the resignation by a few days.
     */
    public function resign(Employee $employee, string $reason, CarbonInterface|string|null $requestedLastDay = null, ?User $actor = null, CarbonInterface|string|null $resignedOn = null, ?int $separationReasonId = null): ExitCase
    {
        $notice = $this->noticeDays($employee);
        $resigned = $resignedOn ? Carbon::parse($resignedOn)->startOfDay() : now()->startOfDay();
        $back = (int) (app(PolicyResolver::class)->resolve('exit', $employee, now())?->setting('employee_backdate_days') ?? 0);
        if ($resigned->gt(now()->startOfDay()) || $resigned->lt(now()->startOfDay()->subDays($back))) {
            throw new RuntimeException($back > 0 ? "The resignation date can be at most {$back} day(s) back." : 'The resignation date is today.');
        }
        $requested = $requestedLastDay ? Carbon::parse($requestedLastDay)->startOfDay() : null;
        $lwd = $requested && $requested->lt($resigned->copy()->addDays($notice)) ? $requested : $resigned->copy()->addDays($notice);

        $case = $this->initiate($employee, 'resignation', $reason, $resigned, $lwd, $notice, $actor, ['workforce_reason_id' => $separationReasonId, 'by_employee' => true]);
        // HROne setup parity P5: the employee's own resignation, for its approval workflow (Workflow → Resignation).
        ExitEvent::dispatch('exit.resignation_submitted', $employee, $case, ['number' => $case->number, 'last_working_day' => $case->last_working_day->toDateString()]);

        return $case;
    }

    /**
     * HROne setup parity P5: the resignation is accepted (the outcome of its approval workflow, in the name of the person
     * who completed the last step). Not accepted, a workflow withdraws it instead (withdraw()).
     */
    public function acceptResignation(ExitCase $case, User $actor, ?string $note = null): ExitCase
    {
        return DB::transaction(function () use ($case, $actor, $note) {
            $locked = ExitCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->type !== 'resignation' || ! $locked->isOpen() || $locked->resignation_accepted_at !== null) {
                throw new RuntimeException('Only an open resignation not yet accepted can be accepted.');
            }
            $employee = $locked->employee()->firstOrFail();
            if ((int) $employee->user_id === (int) $actor->id) {
                throw new RuntimeException('Nobody accepts their own resignation.');
            }
            $locked->withAuditReason($note ?: 'Resignation accepted')->update(['resignation_accepted_at' => now(), 'resignation_accepted_by' => $actor->id]);
            $this->timeline->record($employee, 'exit', 'Resignation accepted', now(), null, $locked);
            ExitEvent::dispatch('exit.resignation_accepted', $employee, $locked, ['number' => $locked->number, 'last_working_day' => $locked->last_working_day->toDateString()], array_filter([$employee->user_id]));

            return $locked;
        });
    }

    /**
     * HROne setup parity CA-3: the separation reason (from the master: one offered to employees when they resign, to HR
     * otherwise, with the remark each side must give) and, when HR marks the employee not eligible for rehire, the
     * blacklist reason (required once the organisation keeps such reasons).
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function reasons(array $extra, ?string $remark): array
    {
        $byEmployee = (bool) ($extra['by_employee'] ?? false);
        $separation = null;
        if (filled($extra['workforce_reason_id'] ?? null)) {
            $separation = WorkforceReason::query()->whereKey($extra['workforce_reason_id'])->where('kind', 'separation')->where('status', 'active')->first()
                ?? throw new RuntimeException('Choose an active separation reason.');
            if ($byEmployee ? ! $separation->for_employee : ! $separation->for_approver) {
                throw new RuntimeException($byEmployee ? 'That reason is not offered to employees.' : 'That reason is offered to employees resigning, not to HR.');
            }
            if (blank($remark) && ($byEmployee ? $separation->remark_required : $separation->approver_remark_required)) {
                throw new RuntimeException("The reason \"{$separation->name}\" needs a remark.");
            }
        } elseif ($byEmployee && WorkforceReason::query()->ofKind('separation')->where('for_employee', true)->exists()) {
            throw new RuntimeException('Choose the reason for leaving.');
        }

        $block = null;
        if (array_key_exists('is_rehire_eligible', $extra) && $extra['is_rehire_eligible'] === false) {
            if (filled($extra['rehire_block_reason_id'] ?? null)) {
                $block = WorkforceReason::query()->whereKey($extra['rehire_block_reason_id'])->where('kind', 'blacklist')->where('status', 'active')->first()
                    ?? throw new RuntimeException('Choose an active blacklist reason.');
            } elseif (WorkforceReason::query()->ofKind('blacklist')->exists()) {
                throw new RuntimeException('Say why the employee may not be rehired.');
            }
        }

        return [$separation?->id, $block?->id];
    }

    /** Notice days: the exit policy (probation or confirmed), else the organisation setting. */
    public function noticeDays(Employee $employee): int
    {
        $policy = app(PolicyResolver::class)->resolve('exit', $employee, now());
        $probation = in_array($employee->lifecycle_state, [LifecycleState::Joined, LifecycleState::Probation], true);
        $days = $probation && is_numeric($policy?->setting('notice_days_probation')) ? $policy->setting('notice_days_probation') : $policy?->setting('notice_period_days');

        return is_numeric($days) ? (int) $days : (int) $this->settings->get('exit.notice_days', 30);
    }

    private function buildClearances(ExitCase $case, Employee $employee): void
    {
        // HROne setup parity: the exit policy may map a clearance checklist whose items replace a stage's defaults.
        $checklistId = app(PolicyResolver::class)->resolve('exit', $employee, now())?->setting('clearance_checklist_id');
        $checklist = $checklistId ? ClearanceChecklist::query()->where('status', 'active')->find($checklistId) : null;
        $roleFor = fn (string $setting) => Role::query()->where('slug', $this->settings->get($setting))->value('id');
        $assets = $this->assets->clearanceFor($employee);
        $i = 0;

        foreach (config('peopleos.exit.clearance_stages', []) as $stage => $definition) {
            $items = collect($definition['items'])->map(fn ($item) => ['item' => $item, 'done' => false])->all();
            if ($checklist !== null && ($own = $checklist->itemsFor($stage)) !== []) {
                $items = $own;
            }
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
            if ($clearance->stage === 'asset') {
                // HROne setup parity AS-A: the owner roles of the asset types the employee still holds hear about it too.
                $recipients = [...$recipients, ...$this->assetOwnerUserIds($case->employee)];
            }
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

        $waived = [];
        if ($clearance->stage === 'asset') {
            $inCustody = $this->assets->clearanceFor($case->employee);
            if ($inCustody->isNotEmpty() && $recoverable <= 0) {
                $tags = $inCustody->map(fn ($a) => $a->asset->asset_tag)->implode(', ');
                // HROne setup parity AS-A (asset.exit.recovery_required): recovery is mandatory unless the organisation
                // lets the stage clear with a waiver, which then needs its reason (audited with the assets waived).
                if ((bool) $this->settings->get('asset.exit.recovery_required', true)) {
                    throw new RuntimeException('Assets still in custody: '.$tags.'. Take them back, or record a recoverable amount.');
                }
                if (blank($remarks)) {
                    throw new RuntimeException('Assets still in custody: '.$tags.'. Give the reason for waiving their recovery in the remarks.');
                }
                $waived = $inCustody->map(fn ($a) => $a->asset->asset_tag)->values()->all();
            }
        }
        // HROne setup parity EX-E (expense.exit.pending_reports): expense reports still waiting for a decision may hold the
        // finance clearance; approved ones are paid in the full and final settlement.
        if ($clearance->stage === 'finance' && $this->settings->get('expense.exit.pending_reports', 'ignore') === 'block'
            && ($waiting = app(ExpenseReports::class)->waitingForDecision($case->employee)) !== []) {
            throw new RuntimeException('Expense reports still waiting for approval: '.implode(', ', $waiting).'. Decide them before clearing finance.');
        }

        $items = collect($clearance->items ?? [])->map(fn ($item, $i) => ['item' => $item['item'], 'done' => $itemsDone === null ? true : (bool) ($itemsDone[$i] ?? false)])->all();

        return DB::transaction(function () use ($clearance, $case, $actor, $remarks, $recoverable, $items, $waived) {
            $clearance->update(['status' => 'cleared', 'remarks' => $remarks, 'recoverable_amount' => round($recoverable, 2), 'items' => $items, 'cleared_by' => $actor->id, 'cleared_at' => now()]);
            $this->audit->record(AuditAction::Approved, 'exit', $clearance, [['field' => 'status', 'before' => 'pending', 'after' => 'cleared']], $remarks, actor: $actor, metadata: ['exit_case' => $case->number, 'recoverable' => $recoverable] + ($waived === [] ? [] : ['asset_recovery_waived' => $waived]));
            ExitEvent::dispatch('exit.clearance.cleared', $case->employee, $clearance, ['number' => $case->number, 'stage' => $clearance->name], array_filter([$case->initiated_by]));

            if ($case->refresh()->allCleared()) {
                $case->update(['status' => 'settlement']);
                app(FinalSettlements::class)->ensure($case);
            }

            return $clearance->refresh();
        });
    }

    /** @return list<int> users holding the owner roles of the asset types still in the employee's custody */
    private function assetOwnerUserIds(Employee $employee): array
    {
        $roleIds = $this->assets->clearanceFor($employee)->map(fn ($a) => $a->asset->category?->owner_role_id)->filter()->unique()->values()->all();

        return $roleIds === [] ? [] : Role::query()->whereIn('id', $roleIds)->get()->flatMap(fn (Role $role) => $role->users()->pluck('users.id')->all())->map(fn ($id) => (int) $id)->unique()->values()->all();
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
            app(FeedbackRequests::class)->cancelFor($case, 'Resignation withdrawn');
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
            PolicyLetters::draft(app(PolicyResolver::class)->resolve('exit', $employee, $case->last_working_day)?->setting('letter_on_completion'), $employee, $actor, $case);

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

    /** HROne setup parity (Code series): the exit case code series of the company (built-in EXIT-YYYY-NNNNN), counted under a lock. */
    public function nextNumber(?int $companyId = null): string
    {
        return app(NumberSeries::class)->next('exit_case', $companyId);
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
