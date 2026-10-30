<?php

namespace App\Domain\Experience\Sources;

use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ApprovalSource;
use App\Domain\Experience\Contracts\DescribesApprovalKinds;
use App\Domain\Experience\Contracts\SupportsSendBack;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Models\LoanGuarantor;
use App\Domain\Loans\Services\Loans;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Domain\Payroll\Services\VariablePay;
use App\Domain\Workflow\Support\RequestWorkflows;
use App\Filament\Resources\Loans\LoanResource;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/** HROne parity HP-2 decisions in the Approval Center: loans and salary advances (claims and variable pay follow). */
final class PayrollRequestsApprovalSource implements ApprovalSource, DescribesApprovalKinds, SupportsSendBack
{
    public function types(): array
    {
        return ['loan', 'variable_pay', 'reimbursement_claim', 'loan_guarantee'];
    }

    /** HROne setup parity P3: request names for Setup → Inbox actions. */
    public function kindLabels(): array
    {
        return ['loan' => 'Loan / salary advance', 'variable_pay' => 'Variable pay', 'reimbursement_claim' => 'Reimbursement claim', 'loan_guarantee' => 'Loan guarantee'];
    }

    /** HROne setup parity P3: a pending loan or claim may go back to its requester (it stays pending meanwhile). */
    public function sendBackTypes(): array
    {
        return ['loan', 'reimbursement_claim'];
    }

    public function pending(User $user, ?int $about = null): array
    {
        // HROne setup parity BP-9: a colleague named as guarantor accepts or declines here.
        $guarantees = $this->guarantees($user, $about);
        if (! $user->hasPermission('payroll.manage')) {
            return $guarantees;
        }
        // HROne setup parity P5: a request a workflow decides is decided through its workflow task, not here.
        $variable = VariablePayRequest::query()->with(['employee.person', 'requester', 'component'])->where('status', 'pending')->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(200)->get();
        $inWorkflow = RequestWorkflows::inFlight(new VariablePayRequest, $variable->pluck('id'));
        $variable = $variable->reject(fn (VariablePayRequest $r) => in_array((int) $r->id, $inWorkflow, true));
        app(AccessScopes::class)->primeEmployeeIds($user, $variable->pluck('employee_id'));
        $variableItems = $variable->filter(fn (VariablePayRequest $r) => $user->can('approve', $r))->map(fn (VariablePayRequest $r) => new ApprovalItem(
            id: 'variable_pay:'.$r->id, type: 'variable_pay', typeLabel: 'Payroll',
            title: ($r->component?->name ?? 'Variable pay').' · '.number_format((float) $r->amount, 2).' for '.$r->pay_month, subject: $r->employee?->display_name, subjectEmployeeId: $r->employee_id,
            reason: $r->reason, impact: 'Paid with the '.$r->pay_month.' payroll', effectiveOn: null, dueAt: $r->created_at?->copy()->addDays(3), requestedAt: $r->created_at,
            requestedBy: $r->requester?->name, risk: 'normal', riskReason: null, decisions: ['approve', 'reject'], url: null, record: $r,
        ))->values()->all();

        $claims = ReimbursementClaim::query()->with(['employee.person', 'requester', 'component'])->where('status', 'pending')->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(200)->get();
        $inWorkflow = RequestWorkflows::inFlight(new ReimbursementClaim, $claims->pluck('id'));
        $claims = $claims->reject(fn (ReimbursementClaim $c) => in_array((int) $c->id, $inWorkflow, true));
        app(AccessScopes::class)->primeEmployeeIds($user, $claims->pluck('employee_id'));
        $claimItems = $claims->filter(fn (ReimbursementClaim $c) => $user->can('approve', $c))->map(fn (ReimbursementClaim $c) => new ApprovalItem(
            id: 'reimbursement_claim:'.$c->id, type: 'reimbursement_claim', typeLabel: 'Payroll',
            title: ($c->component?->name ?? 'Reimbursement').' claim · '.number_format((float) $c->amount, 2), subject: $c->employee?->display_name, subjectEmployeeId: $c->employee_id,
            reason: $c->description, impact: 'Paid, not taxed, in the next payroll', effectiveOn: null, dueAt: $c->created_at?->copy()->addDays(3), requestedAt: $c->created_at,
            requestedBy: $c->requester?->name, risk: 'normal', riskReason: null, decisions: ['approve', 'reject'], url: null, record: $c,
        ))->values()->all();

        $loans = Loan::query()->with(['employee.person', 'requester'])->where('status', 'pending')->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(200)->get();
        $inWorkflow = RequestWorkflows::inFlight(new Loan, $loans->pluck('id'));
        $loans = $loans->reject(fn (Loan $l) => in_array((int) $l->id, $inWorkflow, true));
        app(AccessScopes::class)->primeEmployeeIds($user, $loans->pluck('employee_id'));

        return [...$guarantees, ...$claimItems, ...$variableItems, ...$loans->filter(fn (Loan $l) => $user->can('approve', $l))->map(fn (Loan $l) => new ApprovalItem(
            id: 'loan:'.$l->id, type: 'loan', typeLabel: 'Payroll',
            title: $l->typeLabel().' · '.number_format((float) $l->principal, 2).' in '.$l->installments.' instalment(s)', subject: $l->employee?->display_name, subjectEmployeeId: $l->employee_id,
            reason: $l->reason, impact: 'About '.number_format((float) $l->emi, 2).' a month recovered from '.$l->first_due_month.($l->disbursed_through === 'payroll' ? '; paid out in the next payroll' : '; paid by bank'),
            // HROne setup parity BP-9: a request the policy let through beyond its limits is flagged for the approver.
            effectiveOn: null, dueAt: $l->created_at?->copy()->addDays(3), requestedAt: $l->created_at, requestedBy: $l->requester?->name,
            risk: $l->exceptionSummary() !== null ? 'high' : 'normal', riskReason: $l->exceptionSummary() !== null ? 'Beyond the loan policy: '.$l->exceptionSummary() : null,
            decisions: ['approve', 'reject'], url: $this->url(), record: $l,
            changes: [['label' => 'Monthly recovery', 'before' => null, 'after' => number_format((float) $l->emi, 2)]],
        ))->values()->all()];
    }

    public function decide(ApprovalItem $item, User $user, string $decision, ?string $note): string
    {
        return match ($item->type) {
            'reimbursement_claim' => tap($decision === 'approve' ? 'Claim approved' : 'Claim rejected', fn () => $decision === 'approve'
                ? app(ReimbursementClaims::class)->approve($item->record, null, $note, $user)
                : app(ReimbursementClaims::class)->reject($item->record, (string) $note, $user)),
            'variable_pay' => tap($decision === 'approve' ? 'Variable pay approved' : 'Variable pay rejected', fn () => $decision === 'approve'
                ? app(VariablePay::class)->approve($item->record, $note, $user)
                : app(VariablePay::class)->reject($item->record, (string) $note, $user)),
            'loan' => tap($decision === 'approve' ? 'Loan approved' : 'Loan rejected', fn () => $decision === 'approve'
                ? app(Loans::class)->approve($item->record, $note, $user)
                : app(Loans::class)->reject($item->record, (string) $note, $user)),
            'loan_guarantee' => tap($decision === 'approve' ? 'Guarantee accepted' : 'Guarantee declined', fn () => app(Loans::class)->decideGuarantee($item->record, $decision === 'approve', $note, $user)),
            default => throw new AuthorizationException('Unsupported item.'),
        };
    }

    /** @return list<ApprovalItem> guarantees waiting for this person's answer */
    private function guarantees(User $user, ?int $about): array
    {
        $mine = Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $user->id)->pluck('id');
        if ($mine->isEmpty()) {
            return [];
        }

        return LoanGuarantor::query()->withoutGlobalScope(AccessScope::class)->with(['loan' => fn ($q) => $q->withoutGlobalScope(AccessScope::class)->with('employee.person')])
            ->whereIn('employee_id', $mine)->where('status', 'pending')->whereHas('loan', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->where('status', 'pending')->when($about !== null, fn ($l) => $l->where('employee_id', $about)))
            ->orderBy('id')->limit(50)->get()
            ->map(fn (LoanGuarantor $g) => new ApprovalItem(
                id: 'loan_guarantee:'.$g->id, type: 'loan_guarantee', typeLabel: 'Payroll',
                title: 'Stand guarantor: '.$g->loan->typeLabel().' · '.number_format((float) $g->loan->principal, 2), subject: $g->loan->employee?->display_name, subjectEmployeeId: $g->loan->employee_id,
                reason: $g->loan->reason, impact: 'If you accept, you stand guarantor for loan '.$g->loan->number.' ('.$g->loan->installments.' instalment(s) of about '.number_format((float) $g->loan->emi, 2).').',
                effectiveOn: null, dueAt: $g->created_at?->copy()->addDays(3), requestedAt: $g->created_at, requestedBy: $g->loan->employee?->display_name, risk: 'normal', riskReason: null,
                decisions: ['approve', 'reject'], url: null, record: $g, decisionLabels: ['approve' => 'Accept', 'reject' => 'Decline'],
            ))->values()->all();
    }

    private function url(): ?string
    {
        try {
            return LoanResource::getUrl('index');
        } catch (Throwable) {
            return null;
        }
    }
}
