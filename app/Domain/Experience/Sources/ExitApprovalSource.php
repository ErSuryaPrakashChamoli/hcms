<?php

namespace App\Domain\Experience\Sources;

use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitWithdrawalRequest;
use App\Domain\Exit\Services\ExitWithdrawals;
use App\Domain\Exit\Services\SeparationDesk;
use App\Domain\Exit\Support\ClearanceOwners;
use App\Domain\Experience\Contracts\ApprovalSource;
use App\Domain\Experience\Contracts\DescribesApprovalKinds;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Workflow\Support\RequestWorkflows;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * HROne parity WO-11 (§23.6.2, §23.11 #17; D-WO8): separation decisions in the Approval Center, for HR (`exit.manage`, the
 * employee in their scope, never the requester or the employee themself), and only where the organisation switched the
 * step on:
 *
 * - `exit_withdrawal`: requests to withdraw a resignation (exit.withdrawal.requires_approval), not while a workflow decides
 *   them (P5); approving withdraws the resignation (ExitWithdrawals).
 * - `resignation_acceptance`: open resignations not yet accepted (exit.resignation.requires_acceptance), not while a
 *   resignation workflow runs; accepting / not accepting goes through SeparationDesk::decide (not accepted = withdrawn).
 * - `exit_clearance` is a request type for out-of-office delegation only (clearance stages are tasks, not approvals): listed
 *   so organisations can let a delegate act on the delegator's clearance stages (ClearanceOwners). It has no items.
 */
final class ExitApprovalSource implements ApprovalSource, DescribesApprovalKinds
{
    public function types(): array
    {
        return ['exit_withdrawal', 'resignation_acceptance', ClearanceOwners::KIND];
    }

    public function kindLabels(): array
    {
        return ['exit_withdrawal' => 'Resignation withdrawal', 'resignation_acceptance' => 'Resignation acceptance', ClearanceOwners::KIND => 'Exit clearance stage (out-of-office delegation)'];
    }

    public function pending(User $user, ?int $about = null): array
    {
        if (! $user->hasPermission('exit.manage')) {
            return [];
        }

        return [...$this->withdrawals($user, $about), ...$this->acceptances($user, $about)];
    }

    public function decide(ApprovalItem $item, User $user, string $decision, ?string $note): string
    {
        if ($item->record instanceof ExitWithdrawalRequest) {
            $withdrawals = app(ExitWithdrawals::class);

            return match ($decision) {
                'approve' => tap('Withdrawal approved: the resignation is withdrawn', fn () => $withdrawals->approve($item->record, $user, $note)),
                'reject' => tap('Withdrawal rejected: the exit goes on', fn () => $withdrawals->reject($item->record, $user, $note)),
                default => throw new AuthorizationException('Unsupported decision.'),
            };
        }
        if ($item->record instanceof ExitCase) {
            return match ($decision) {
                'approve' => tap('Resignation accepted', fn () => app(SeparationDesk::class)->decide($item->record, $user, 'accept', $note)),
                'reject' => tap('Resignation not accepted: it is withdrawn', fn () => app(SeparationDesk::class)->decide($item->record, $user, 'reject', $note)),
                default => throw new AuthorizationException('Unsupported decision.'),
            };
        }

        throw new AuthorizationException('Unsupported item.');
    }

    /** @return list<ApprovalItem> */
    private function withdrawals(User $user, ?int $about): array
    {
        $withdrawals = app(ExitWithdrawals::class);
        if (! $withdrawals->requiresApproval() && ExitWithdrawalRequest::query()->withoutGlobalScope(AccessScope::class)->where('status', 'pending')->doesntExist()) {
            return [];
        }
        $requests = ExitWithdrawalRequest::query()->withoutGlobalScope(AccessScope::class)->with(['employee.person', 'requester', 'exitCase'])->where('status', 'pending')
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(200)->get();
        $inWorkflow = RequestWorkflows::inFlight(new ExitWithdrawalRequest, $requests->pluck('id'));

        return $requests->reject(fn (ExitWithdrawalRequest $r) => in_array((int) $r->id, $inWorkflow, true))
            ->filter(fn (ExitWithdrawalRequest $r) => $withdrawals->mayDecide($user, $r))
            ->map(fn (ExitWithdrawalRequest $r) => new ApprovalItem(
                id: 'exit_withdrawal:'.$r->id, type: 'exit_withdrawal', typeLabel: 'Separation',
                title: 'Withdraw resignation · '.($r->exitCase?->number ?? $r->reference()), subject: $r->employee?->display_name, subjectEmployeeId: $r->employee_id,
                reason: $r->reason, impact: 'Approved, the employee is active again and the open clearance stages become not applicable; rejected, the exit goes on (last working day '.$r->exitCase?->last_working_day?->format('d M Y').').',
                effectiveOn: null, dueAt: $r->created_at?->copy()->addDays(3), requestedAt: $r->created_at, requestedBy: $r->requester?->name,
                risk: 'normal', riskReason: null, decisions: ['approve', 'reject'], url: $this->url($r->exitCase), record: $r,
                decisionLabels: ['approve' => 'Approve withdrawal', 'reject' => 'Reject'],
            ))->values()->all();
    }

    /** @return list<ApprovalItem> */
    private function acceptances(User $user, ?int $about): array
    {
        $desk = app(SeparationDesk::class);
        if (! $desk->requiresAcceptance()) {
            return [];
        }
        $cases = ExitCase::query()->withoutGlobalScope(AccessScope::class)->with(['employee.person', 'initiator'])->where('type', 'resignation')->whereIn('status', ExitCase::OPEN)
            ->whereNull('resignation_accepted_at')->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(200)->get();
        $inWorkflow = RequestWorkflows::inFlight(new ExitCase, $cases->pluck('id'));
        app(AccessScopes::class)->primeEmployeeIds($user, $cases->pluck('employee_id'));

        return $cases->reject(fn (ExitCase $c) => in_array((int) $c->id, $inWorkflow, true))
            ->filter(fn (ExitCase $c) => $desk->mayDecide($user, $c))
            ->map(fn (ExitCase $c) => new ApprovalItem(
                id: 'resignation_acceptance:'.$c->id, type: 'resignation_acceptance', typeLabel: 'Separation',
                title: 'Resignation · '.$c->number.' · last day '.$c->last_working_day->format('d M Y'), subject: $c->employee?->display_name, subjectEmployeeId: $c->employee_id,
                reason: $c->reason, impact: 'Accepted, the exit runs to its last working day; not accepted, the resignation is withdrawn and the employee is active again.',
                effectiveOn: $c->last_working_day, dueAt: $c->created_at?->copy()->addDays(3), requestedAt: $c->created_at, requestedBy: $c->initiator?->name,
                risk: 'normal', riskReason: null, decisions: ['approve', 'reject'], url: $this->url($c), record: $c,
                decisionLabels: ['approve' => 'Accept', 'reject' => 'Do not accept'],
            ))->values()->all();
    }

    private function url(?ExitCase $case): ?string
    {
        try {
            return $case !== null ? ExitCaseResource::getUrl('view', ['record' => $case]) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
