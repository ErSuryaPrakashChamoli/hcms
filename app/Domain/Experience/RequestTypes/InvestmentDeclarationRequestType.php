<?php

namespace App\Domain\Experience\RequestTypes;

use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\InvestmentDeclarations;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Pages\DeclareInvestments;
use App\Filament\Resources\TaxDeclarations\TaxDeclarationResource;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity RQ-1: proposed investment declaration (Declare investments). Offered while the person may change this
 * year's declaration (the declaration window, InvestmentDeclarations::canEdit).
 */
final class InvestmentDeclarationRequestType extends BaseRequestType
{
    public function key(): string
    {
        return 'investment_declaration';
    }

    public function label(): string
    {
        return 'Propose investments';
    }

    public function description(): string
    {
        return 'Declare your investments and rent for this year’s income tax';
    }

    public function module(): string
    {
        return 'Tax';
    }

    public function icon(): string
    {
        return 'heroicon-o-clipboard-document-check';
    }

    public function keywords(): array
    {
        return ['investment', 'declaration', 'tax', '80c', 'hra', 'rent', 'propose invest'];
    }

    public function permission(): string
    {
        return 'payroll.payslip';
    }

    public function availableTo(User $user, ?Employee $employee): bool
    {
        if (! $this->signedIn($user) || $employee === null || ! DeclareInvestments::canAccess()) {
            return false;
        }
        $declarations = app(InvestmentDeclarations::class);

        return $declarations->canEdit($user, $employee, $declarations->declaration($employee, app(FinancialYear::class)->label(now())));
    }

    public function formUrl(User $user, ?Employee $employee): ?string
    {
        return DeclareInvestments::getUrl();
    }

    public function historyVisibleTo(User $user): bool
    {
        return $this->signedIn($user) && (DeclareInvestments::canAccess() || TaxDeclarationResource::canAccess());
    }

    public function model(): ?string
    {
        return EmployeeTaxDeclaration::class;
    }

    protected function resource(): ?string
    {
        return TaxDeclarationResource::class;
    }

    public function statusMap(): array
    {
        return [
            'draft' => RequestStatus::Draft,
            'submitted' => RequestStatus::Pending,
            'verified' => RequestStatus::Approved,
        ];
    }

    public function dateFilters(): array
    {
        return [
            'requested' => ['label' => 'Started on', 'column' => 'created_at'],
            'decided' => ['label' => 'Verified on', 'column' => 'verified_at'],
        ];
    }

    public function searchColumns(): array
    {
        return ['financial_year'];
    }

    public function summary(Model $record): array
    {
        /** @var EmployeeTaxDeclaration $record */
        return [
            'title' => $this->join('Investment declaration', 'FY '.$record->financial_year),
            'detail' => $record->regime ? ucfirst((string) $record->regime).' regime' : null,
        ];
    }

    public function recordUrl(User $user, Model $record): ?string
    {
        if ($this->signedIn($user) && DeclareInvestments::canAccess()) {
            return DeclareInvestments::getUrl(['year' => $record->getAttribute('financial_year')]);
        }

        return parent::recordUrl($user, $record);
    }

    // -- approval route (RQ-2) ----------------------------------------------------------------------------------

    public function raisedEvents(): array
    {
        return ['payroll.tax_declaration_submitted'];
    }

    public function builtInApprovers(): string
    {
        return 'The payroll team verifies it';
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
