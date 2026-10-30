<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Filament\Resources\ReimbursementClaims\ReimbursementClaimResource;
use Illuminate\Database\Eloquent\Model;

/** HROne parity RQ-1: reimbursement claim (Reimbursement claims → "Claim a reimbursement"). */
final class ReimbursementClaimRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'reimbursement';
    }

    public function label(): string
    {
        return 'Reimbursement';
    }

    public function description(): string
    {
        return 'Claim a reimbursement your pay includes (fuel, phone, medical …)';
    }

    public function module(): string
    {
        return 'Payroll';
    }

    public function icon(): string
    {
        return 'heroicon-o-receipt-percent';
    }

    public function keywords(): array
    {
        return ['reimbursement', 'claim', 'bill', 'fuel', 'phone', 'medical'];
    }

    public function permission(): string
    {
        return 'payroll.request';
    }

    /** The claim form offers reimbursement components paid on claim; without one there is nothing to claim. */
    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && $user->can('payroll.request') && ReimbursementClaimResource::canAccess()
            && SalaryComponent::query()->where('type', 'reimbursement')->where('pay_on_claim', true)->exists();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return $this->withAction(ReimbursementClaimResource::getUrl('index'), 'claim');
    }

    public function model(): ?string
    {
        return ReimbursementClaim::class;
    }

    protected function resource(): ?string
    {
        return ReimbursementClaimResource::class;
    }

    protected function with(): array
    {
        return ['component'];
    }

    public function statusMap(): array
    {
        return [
            'pending' => RequestStatus::Pending,
            'approved' => RequestStatus::Approved,
            'paid' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
            'cancelled' => RequestStatus::Withdrawn,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Requested on', 'column' => 'created_at'],
            'effective' => ['label' => 'Claim date', 'column' => 'claim_date'],
            'decided' => ['label' => 'Decided on', 'column' => 'reviewed_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['description'];
    }

    public function summary(Model $record): array
    {
        /** @var ReimbursementClaim $record */
        return [
            'title' => $this->join($record->component?->name ?? 'Reimbursement', number_format((float) $record->amount, 2), $record->claim_date?->format('d M Y')),
            'detail' => $this->short($record->description),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['payroll.claim_submitted'];
    }

    public function builtInApprovers(): string
    {
        return 'Payroll approvers in the Approval Center';
    }

    public function approverPermissions(): array
    {
        return ['payroll.manage'];
    }

    public function approvalKind(Model $record): ?string
    {
        return 'reimbursement_claim';
    }

    public function knowledgeCategory(): ?string
    {
        return 'payroll';
    }
}
