<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Services\TaxSettings;
use App\Domain\Compliance\Support\InvestmentCatalogue;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\MyInvestmentProofs\MyInvestmentProofResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity RQ-1: confirmed investments — the proofs of declared items (My investment proofs → "Submit proof" per item).
 * Offered while proofs may be submitted (the proof window; payroll always may) and an item still needs one. A proof is a
 * request once submitted; a declared item without a proof is not listed.
 */
final class InvestmentProofRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'investment_proof';
    }

    public function label(): string
    {
        return 'Confirm investments';
    }

    public function description(): string
    {
        return 'Send the proofs of the investments you declared';
    }

    public function module(): string
    {
        return 'Tax';
    }

    public function icon(): string
    {
        return 'heroicon-o-paper-clip';
    }

    public function keywords(): array
    {
        return ['proof', 'investment proof', 'confirm invest', 'tax proof'];
    }

    public function permission(): string
    {
        return 'payroll.payslip';
    }

    public function availableTo(User $user, ?Employee $employee): bool
    {
        return $this->signedIn($user) && $employee !== null && MyInvestmentProofResource::canAccess()
            && ($user->hasPermission('payroll.manage') || app(TaxSettings::class)->proofOpen())
            && TdsEmployeeInvestment::query()->where('employee_id', $employee->getKey())->where('proof_status', '!=', 'verified')->exists();
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return MyInvestmentProofResource::getUrl('index');
    }

    public function model(): ?string
    {
        return TdsEmployeeInvestment::class;
    }

    protected function resource(): ?string
    {
        return MyInvestmentProofResource::class;
    }

    protected function statusColumn(): string
    {
        return 'proof_status';
    }

    public function ownRecords(User $user, ?Employee $employee): ?Builder
    {
        return parent::ownRecords($user, $employee)?->whereNotNull('tds_employee_investments.proof_submitted_at');
    }

    public function statusMap(): array
    {
        return [
            'pending' => RequestStatus::Pending,
            'verified' => RequestStatus::Approved,
            'rejected' => RequestStatus::Rejected,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Proof sent on', 'column' => 'proof_submitted_at'],
            'decided' => ['label' => 'Verified on', 'column' => 'verified_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['description', 'financial_year'];
    }

    public function summary(Model $record): array
    {
        /** @var TdsEmployeeInvestment $record */
        return [
            'title' => $this->join('Proof', InvestmentCatalogue::SECTIONS[$record->section]['code'] ?? $record->section, $this->short($record->description, 60)),
            'detail' => $this->join('FY '.$record->financial_year, number_format((float) ($record->proof_claimed_amount ?? $record->declared_amount), 2)),
        ];
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return [];
    }

    public function builtInApprovers(): string
    {
        return 'The payroll team verifies your proofs';
    }

    public function approverPermissions(): array
    {
        return [];
    }

    public function knowledgeCategory(): ?string
    {
        return 'payroll';
    }
}
