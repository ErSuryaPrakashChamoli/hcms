<?php

namespace App\Filament\Resources\TaxDeclarations\Pages;

use App\Filament\Pages\DeclareInvestments;
use App\Filament\Resources\TaxDeclarations\TaxDeclarationResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleManageRecords;
use App\Filament\Support\PayrollRegisters;
use Filament\Actions\Action;

class ManageTaxDeclarations extends PeopleManageRecords
{
    use HasRegisterTools;

    protected static string $resource = TaxDeclarationResource::class;

    public static function registerKey(): string
    {
        return 'tax_declarations';
    }

    public static function presetViews(): array
    {
        return [
            'submitted' => ['label' => 'Proposed investments to verify', 'filters' => ['status' => ['value' => 'submitted']]],
            'verified' => ['label' => 'Verified', 'filters' => ['status' => ['value' => 'verified']]],
            'draft' => ['label' => 'Draft (not yet submitted)', 'filters' => ['status' => ['value' => 'draft']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['investment_declarations', 'declaration_status_summary', 'tax_regime_split'];
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity PO-1: views and report for payroll; the employee's own declaration keeps its action.
            ...(PayrollRegisters::hrSide('payroll.view') ? $this->registerToolActions() : []),
            Action::make('declare')->label('Declare investments')->icon('heroicon-m-clipboard-document-check')
                ->visible(fn () => DeclareInvestments::canAccess())
                ->url(fn () => DeclareInvestments::getUrl()),
        ];
    }
}
