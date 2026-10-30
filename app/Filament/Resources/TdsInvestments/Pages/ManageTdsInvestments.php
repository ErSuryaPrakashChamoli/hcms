<?php

namespace App\Filament\Resources\TdsInvestments\Pages;

use App\Filament\Pages\DeclareInvestments;
use App\Filament\Resources\TdsInvestments\TdsInvestmentResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleManageRecords;
use App\Filament\Support\PayrollRegisters;
use Filament\Actions\Action;

class ManageTdsInvestments extends PeopleManageRecords
{
    use HasRegisterTools;

    protected static string $resource = TdsInvestmentResource::class;

    public static function registerKey(): string
    {
        return 'tds_investments';
    }

    public static function presetViews(): array
    {
        return [
            'pending' => ['label' => 'Confirmed investments: proofs to verify', 'filters' => ['proof_status' => ['value' => 'pending']]],
            'verified' => ['label' => 'Proofs verified', 'filters' => ['proof_status' => ['value' => 'verified']]],
            'rejected' => ['label' => 'Proofs rejected', 'filters' => ['proof_status' => ['value' => 'rejected']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['proofs_pending', 'section_wise_declarations'];
    }

    protected function getHeaderActions(): array
    {
        // Items are declared section by section in the guided declaration; this screen verifies their proofs.
        return [
            // HROne parity PO-1: views and report for payroll; the employee's own proofs keep their action.
            ...(PayrollRegisters::hrSide('payroll.view') ? $this->registerToolActions() : []),
            Action::make('declare')->label('Declare investments')->icon('heroicon-m-clipboard-document-check')
                ->visible(fn () => DeclareInvestments::canAccess())
                ->url(fn () => DeclareInvestments::getUrl()),
        ];
    }
}
