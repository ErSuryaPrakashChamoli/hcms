<?php

namespace App\Domain\Exit\Services;

use App\Domain\Alumni\Models\AlumniProfile;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\WorkforceReason;
use App\Domain\Exit\Events\ExitEvent;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Exit\Models\ExitWithdrawalRequest;
use App\Domain\Exit\Support\ClearanceDueDates;
use App\Domain\Exit\Support\NoticeShortfall;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Support\RequestWorkflows;
use App\Support\Storage\FileSafety;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * HROne parity WO-11 (matrix 23 §23.6): the separation desk's decisions on an exit case, beside Exits (which runs the
 * exit itself). Every change is HR's (`exit.manage`, the employee in the person's access scope; stage owners for their
 * own stages), audited with its reason and kept on the case:
 *
 * - accept / reject a resignation without a workflow (`exit.resignation.requires_acceptance`, off by default: the
 *   resignation runs unaccepted, as before); the approver may set the approver's separation reason (kept apart from the
 *   employee's), change the last working day and grant notice relaxation days in the same decision. A rejection
 *   withdraws the resignation, as a rejected resignation workflow does (WRK-4).
 * - change the last working day (a reason; never before the resignation date nor backdated beyond the exit policy's
 *   `hr_backdate_days`; stage due dates follow; the notice shortfall is recomputed from the new day);
 * - notice relaxation days (part of the shortfall waived; 0 by default);
 * - rehire eligibility on any open or completed case, and "not eligible for rehire" on an exited employee (the
 *   blacklist reason required once the organisation keeps such reasons, as CA-3 does at initiation);
 * - the month the full and final settlement is to be paid in (blank = the month of the last working day);
 * - clearance stages: the expected date, an evidence file, cancel with a reason (D-WO3: closed and kept, never deleted),
 *   "not applicable — exit withdrawn" for the open stages of a withdrawn exit.
 */
final class SeparationDesk
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Timeline $timeline,
    ) {}

    public function requiresAcceptance(): bool
    {
        return (bool) $this->settings->get('exit.resignation.requires_acceptance', false);
    }

    /** An open resignation waiting for HR's acceptance (the setting on, not accepted, no deciding workflow in flight). */
    public function awaitsAcceptance(ExitCase $case): bool
    {
        $attributes = $case->getAttributes();

        return $this->requiresAcceptance() && ($attributes['type'] ?? null) === 'resignation' && in_array($attributes['status'] ?? null, ExitCase::OPEN, true)
            && ($attributes['resignation_accepted_at'] ?? null) === null && RequestWorkflows::inFlight(new ExitCase, [$case->getKey()]) === [];
    }

    /** Whether the person may decide (accept or reject) this resignation: HR in scope, never their own. */
    public function mayDecide(User $user, ExitCase $case): bool
    {
        return $user->isActive() && $user->hasPermission('exit.manage') && app(AccessScopes::class)->allows($user, $case)
            && (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($case->employee_id)->value('user_id') !== (int) $user->id;
    }

    /**
     * Accept or reject the resignation. Details on acceptance (each optional): approver_reason_id + approver_remark,
     * last_working_day + last_working_day_reason, notice_relaxation_days.
     *
     * @param  array<string, mixed>  $details
     */
    public function decide(ExitCase $case, User $actor, string $decision, ?string $note = null, array $details = []): ExitCase
    {
        if (! $this->requiresAcceptance()) {
            throw new RuntimeException('Resignations here run without an acceptance step (Setup → Workforce).');
        }
        if (! in_array($decision, ['accept', 'reject'], true)) {
            throw new RuntimeException('Accept or reject the resignation.');
        }
        if (! $this->mayDecide($actor, $case)) {
            throw new RuntimeException('You cannot decide this resignation.');
        }
        if (! $this->awaitsAcceptance($case->refresh())) {
            throw new RuntimeException($case->resignation_accepted_at !== null ? 'This resignation is already accepted.' : 'This resignation is not waiting for acceptance (it is closed, or its approval workflow decides it).');
        }
        $note = self::text($note, 1000);

        if ($decision === 'reject') {
            if ($note === null) {
                throw new RuntimeException('Give the reason for not accepting the resignation.');
            }

            return app(Exits::class)->withdraw($case, 'Resignation not accepted. '.$note, $actor);
        }

        return DB::transaction(function () use ($case, $actor, $note, $details) {
            if (filled($details['approver_reason_id'] ?? null)) {
                $this->setApproverReason($case, (int) $details['approver_reason_id'], $details['approver_remark'] ?? null, $actor);
            }
            if (filled($details['last_working_day'] ?? null) && Carbon::parse($details['last_working_day'])->toDateString() !== $case->refresh()->last_working_day->toDateString()) {
                $this->changeLastWorkingDay($case, $details['last_working_day'], (string) ($details['last_working_day_reason'] ?? ''), $actor);
            }
            if (isset($details['notice_relaxation_days']) && $details['notice_relaxation_days'] !== '' && (int) $details['notice_relaxation_days'] !== (int) $case->refresh()->notice_relaxation_days) {
                $this->setNoticeRelaxation($case, (int) $details['notice_relaxation_days'], $note ?? 'Granted on acceptance', $actor);
            }

            return app(Exits::class)->acceptResignation($case->refresh(), $actor, $note);
        });
    }

    /** §23.6.1 #8, #17: the approver's separation reason (a reason offered to approvers), with its remark rule. */
    public function setApproverReason(ExitCase $case, ?int $reasonId, ?string $remark, User $actor): ExitCase
    {
        $this->assertManages($actor, $case, open: false);
        $remark = self::text($remark, 2000);
        $reason = null;
        if ($reasonId !== null) {
            $reason = WorkforceReason::query()->whereKey($reasonId)->where('kind', 'separation')->where('status', 'active')->first()
                ?? throw new RuntimeException('Choose an active separation reason.');
            if (! $reason->for_approver) {
                throw new RuntimeException('That reason is offered to employees resigning, not to approvers.');
            }
            if ($remark === null && $reason->approver_remark_required) {
                throw new RuntimeException("The reason \"{$reason->name}\" needs a remark.");
            }
        }
        $case->withAuditReason('Approver\'s separation reason')->update(['approver_reason_id' => $reason?->id, 'approver_remark' => $remark]);

        return $case;
    }

    /** §23.6.1 #9: change the last working day — a reason, within the exit policy's backdating, stage due dates follow. */
    public function changeLastWorkingDay(ExitCase $case, CarbonInterface|string $date, string $reason, User $actor): ExitCase
    {
        $this->assertManages($actor, $case);
        $this->assertSettlementOpen($case);
        $reason = self::text($reason, 500) ?? throw new RuntimeException('Give the reason for changing the last working day.');
        $new = Carbon::parse($date)->startOfDay();
        $old = $case->last_working_day->copy();
        if ($new->equalTo($old)) {
            throw new RuntimeException('That is already the last working day.');
        }
        $start = $case->resignation_date ?? $case->initiated_on;
        if ($start !== null && $new->lt($start)) {
            throw new RuntimeException('The last working day cannot be before the resignation date.');
        }
        $employee = $case->employee()->firstOrFail();
        $back = app(PolicyResolver::class)->resolve('exit', $employee, now())?->setting('hr_backdate_days');
        if (is_numeric($back) && $new->lt(now()->startOfDay()->subDays((int) $back))) {
            throw new RuntimeException("The last working day can be backdated by at most {$back} day(s).");
        }

        DB::transaction(function () use ($case, $employee, $new, $old, $reason) {
            $case->withAuditReason($reason)->update(['last_working_day' => $new]);
            $employee->update(['exit_date' => $new]);
            foreach ($case->clearances()->get() as $stage) {
                $due = ClearanceDueDates::dueOn($new, ClearanceDueDates::offset($stage->stage, (array) ($stage->items ?? []), $employee));
                if ($due?->toDateString() !== $stage->due_on?->toDateString()) {
                    $stage->withAuditReason('Last working day changed')->update(['due_on' => $due]);
                }
            }
            $this->timeline->record($employee, 'exit', 'Last working day changed', now(), $old->format('d M Y').' → '.$new->format('d M Y'), $case, ['last_working_day' => $new->toDateString()]);
            ExitEvent::dispatch('exit.last_working_day_changed', $employee, $case, ['number' => $case->number, 'last_working_day' => $new->toDateString(), 'previous' => $old->toDateString()],
                array_values(array_filter([$employee->user_id, $case->manager()->value('user_id')])));
        });

        $case->refresh();
        // As at initiation: an exit whose last day falls within the clearance lead window starts its clearance.
        if (in_array($case->status, ['initiated', 'notice'], true) && $case->last_working_day->lte(now()->addDays((int) $this->settings->get('exit.clearance_lead_days', 7)))) {
            app(Exits::class)->startClearance($case, $actor);
        }

        return $case->refresh();
    }

    /** §23.6.1 #10: waive part of the notice shortfall (0 … the notice period). */
    public function setNoticeRelaxation(ExitCase $case, int $days, ?string $reason, User $actor): ExitCase
    {
        $this->assertManages($actor, $case);
        $this->assertSettlementOpen($case);
        if ($case->type !== 'resignation') {
            throw new RuntimeException('Notice relaxation applies to resignations only.');
        }
        if ($days < 0 || $days > (int) $case->notice_days) {
            throw new RuntimeException('Relax between 0 and '.(int) $case->notice_days.' day(s) (the notice period).');
        }
        $reason = self::text($reason, 500) ?? throw new RuntimeException('Give the reason for the relaxation.');
        $case->withAuditReason($reason)->update(['notice_relaxation_days' => $days]);

        return $case;
    }

    /** §23.6.1 #11: rehire eligibility on any open or completed case (a blacklist reason once the organisation keeps them). */
    public function setRehireEligibility(ExitCase $case, bool $eligible, ?int $blockReasonId, ?string $comment, User $actor): ExitCase
    {
        $this->assertManages($actor, $case, open: false);
        if (! $case->isOpen() && $case->status !== 'completed') {
            throw new RuntimeException('Rehire eligibility is set on an open or completed exit.');
        }
        $comment = self::text($comment, 500);
        $block = null;
        if (! $eligible) {
            if ($blockReasonId !== null) {
                $block = WorkforceReason::query()->whereKey($blockReasonId)->where('kind', 'blacklist')->where('status', 'active')->first()
                    ?? throw new RuntimeException('Choose an active blacklist reason.');
            } elseif (WorkforceReason::query()->ofKind('blacklist')->exists()) {
                throw new RuntimeException('Say why the employee may not be rehired.');
            }
        }

        DB::transaction(function () use ($case, $eligible, $block, $comment) {
            $case->withAuditReason($comment ?? ($eligible ? 'Eligible for rehire' : 'Not eligible for rehire'))
                ->update(['is_rehire_eligible' => $eligible, 'rehire_block_reason_id' => $block?->id, 'rehire_comment' => $comment]);
            AlumniProfile::query()->where('employee_id', $case->employee_id)->where('exit_case_id', $case->id)->get()
                ->each(fn (AlumniProfile $alumni) => $alumni->update(['is_rehire_eligible' => $eligible]));
        });

        return $case->refresh();
    }

    /** §23.2.2 #20 ("Blacklist"): an exited employee (or alumnus) marked not eligible for rehire, on their latest exit. */
    public function markNotEligibleForRehire(Employee $employee, ?int $blockReasonId, ?string $comment, User $actor): ExitCase
    {
        if (! in_array($employee->lifecycle_state, [LifecycleState::Exited, LifecycleState::Alumni], true)) {
            throw new RuntimeException('Only an employee who has left can be marked not eligible for rehire.');
        }
        $case = ExitCase::query()->where('employee_id', $employee->id)->where('status', 'completed')->latest('last_working_day')->latest('id')->first()
            ?? throw new RuntimeException('No completed exit is recorded for this employee.');

        return $this->setRehireEligibility($case, false, $blockReasonId, $comment, $actor);
    }

    /** §23.6.1 #14: the month the full and final settlement is paid in ("YYYY-MM"; blank = the last working day's month). */
    public function setSettlementMonth(ExitCase $case, ?string $month, User $actor): ExitCase
    {
        if (! $actor->hasPermission('exit.manage') && ! $actor->hasPermission('exit.settle')) {
            throw new RuntimeException('You cannot change the settlement month.');
        }
        if (! app(AccessScopes::class)->allows($actor, $case) || ! $case->isOpen()) {
            throw new RuntimeException('The settlement month is set on an open exit in your access.');
        }
        if ($case->settlement()->where('status', 'paid')->exists()) {
            throw new RuntimeException('The settlement is already paid.');
        }
        $value = null;
        if (filled($month)) {
            if (! preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
                throw new RuntimeException('Give the month as YYYY-MM.');
            }
            $value = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
            if ($value->lt($case->last_working_day->copy()->startOfMonth())) {
                throw new RuntimeException('The settlement month cannot be before the month of the last working day.');
            }
        }
        $case->withAuditReason('Settlement month')->update(['settlement_month' => $value]);

        return $case;
    }

    /** §23.6.3 #4: the stage owner's expected completion date. */
    public function setExpectedDate(ExitClearance $stage, CarbonInterface|string|null $date, User $actor): ExitClearance
    {
        $this->assertActsOn($stage, $actor);
        if (! $stage->isOpen()) {
            throw new RuntimeException('The stage is already closed.');
        }
        $stage->withAuditReason('Expected completion date')->update(['expected_on' => $date !== null && $date !== '' ? Carbon::parse($date)->startOfDay() : null]);

        return $stage;
    }

    /**
     * §23.6.3 #7: one evidence file on a stage. The upload (in the documents disk's `exit-clearances/` upload area) moves
     * into `tenants/{tenant}/exit-clearances/{stage}/`; its type and size follow the document rules. A new file replaces
     * the earlier one (removed from the disk; the change is audited).
     */
    public function attachEvidence(ExitClearance $stage, string $upload, ?string $originalName, User $actor): ExitClearance
    {
        $this->assertActsOn($stage, $actor);
        $disk = Storage::disk(config('peopleos.documents.disk', 'local'));
        $source = ltrim(str_replace(['\\', '..'], ['/', ''], $upload), '/');
        if (! str_starts_with($source, 'exit-clearances/') || ! $disk->exists($source)) {
            throw new RuntimeException('The evidence upload was not found.');
        }
        $original = $originalName ?? basename($source);
        try {
            $extension = FileSafety::assertAllowed($original, (int) $disk->size($source), FileSafety::sniff((string) $disk->get($source)));
        } catch (RuntimeException $e) {
            $disk->delete($source);
            throw $e;
        }
        $path = "tenants/{$stage->tenant_id}/exit-clearances/{$stage->id}/".Str::ulid().'.'.$extension;
        $disk->move($source, $path);
        $earlier = $stage->getAttributes()['evidence_path'] ?? null;
        $stage->withAuditReason('Evidence attached')->update(['evidence_path' => $path, 'evidence_name' => Str::limit($original, 250, ''), 'evidence_by' => $actor->id, 'evidence_at' => now()]);
        if (is_string($earlier) && $earlier !== $path) {
            $disk->delete($earlier);
        }

        return $stage;
    }

    /** D-WO3: cancel a stage with a reason — closed as not applicable and kept, never deleted. */
    public function cancelStage(ExitClearance $stage, User $actor, string $reason): ExitClearance
    {
        $case = $stage->exitCase()->firstOrFail();
        $this->assertManages($actor, $case);
        if (! $stage->isOpen()) {
            throw new RuntimeException('Only a stage still open can be cancelled.');
        }
        $reason = self::text($reason, 500) ?? throw new RuntimeException('Give the reason for cancelling the stage.');

        return DB::transaction(function () use ($stage, $actor, $reason) {
            app(Exits::class)->markNotApplicable($stage, $actor, 'Cancelled: '.$reason);
            $stage->update(['closed_reason' => 'cancelled']);

            return $stage->refresh();
        });
    }

    /** §23.6.3 #11: the exit was withdrawn — its open stages are not applicable; waiting withdrawal requests close with it. */
    public function closeForWithdrawal(ExitCase $case, ?User $actor = null): void
    {
        $by = $actor?->id ?? auth()->id();
        foreach ($case->clearances()->whereIn('status', ['pending', 'blocked'])->get() as $stage) {
            $stage->withAuditReason('Exit withdrawn')->update(['status' => 'na', 'remarks' => ExitClearance::CLOSED_REASONS['withdrawn'], 'closed_reason' => 'withdrawn', 'cleared_by' => $by, 'cleared_at' => now()]);
        }
        ExitWithdrawalRequest::query()->withoutGlobalScope(AccessScope::class)->where('exit_case_id', $case->id)->where('status', 'pending')->get()
            ->each(fn (ExitWithdrawalRequest $request) => $request->withAuditReason('The resignation was withdrawn')
                ->update(['status' => 'cancelled', 'open_key' => null, 'decided_at' => now(), 'decision_note' => 'The resignation was withdrawn.']));
    }

    /** Refusals before "Complete exit" (only when the organisation switched the steps on); warnings never block. */
    public function assertReadyToComplete(ExitCase $case): void
    {
        $attributes = $case->getAttributes();
        if ($this->requiresAcceptance() && ($attributes['type'] ?? null) === 'resignation' && ($attributes['resignation_accepted_at'] ?? null) === null) {
            throw new RuntimeException('Accept the resignation before completing the exit.');
        }
        if (ExitWithdrawalRequest::query()->withoutGlobalScope(AccessScope::class)->where('exit_case_id', $case->id)->where('status', 'pending')->exists()) {
            throw new RuntimeException('A request to withdraw this resignation is waiting for a decision.');
        }
    }

    /** §23.6.4 #1: every stage cleared or not applicable, the exit still open. */
    public static function readyToComplete(ExitCase $case): bool
    {
        return $case->isOpen() && $case->allCleared();
    }

    /** The notice shortfall now (days), for the case view and the relieving review. */
    public static function shortfall(ExitCase $case): ?array
    {
        return NoticeShortfall::for($case);
    }

    private function assertManages(User $actor, ExitCase $case, bool $open = true): void
    {
        if (! $actor->hasPermission('exit.manage') || ! app(AccessScopes::class)->allows($actor, $case)) {
            throw new RuntimeException('You cannot change this exit.');
        }
        if ($open && ! $case->isOpen()) {
            throw new RuntimeException('The exit is not open.');
        }
    }

    private function assertSettlementOpen(ExitCase $case): void
    {
        if ($case->settlement()->whereIn('status', ['approved', 'paid'])->exists()) {
            throw new RuntimeException('The full & final settlement is already approved.');
        }
    }

    /** HR (exit.manage) or the stage's owner holding exit.clear (P4 delegates included). */
    private function assertActsOn(ExitClearance $stage, User $actor): void
    {
        $case = $stage->exitCase()->first();
        $hr = $actor->hasPermission('exit.manage') && $case !== null && app(AccessScopes::class)->allows($actor, $case);
        if (! $hr && ! ($actor->hasPermission('exit.clear') && $stage->isOwnedBy($actor))) {
            throw new RuntimeException('You cannot act on this clearance stage.');
        }
        if ($case === null || (! $case->isOpen() && $case->status !== 'completed')) {
            throw new RuntimeException('The exit is not open.');
        }
    }

    private static function text(?string $value, int $max): ?string
    {
        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
