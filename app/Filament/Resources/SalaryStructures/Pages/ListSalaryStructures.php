<?php

namespace App\Filament\Resources\SalaryStructures\Pages;

use App\Filament\Pages\SalaryStructureBuilder;
use App\Filament\Resources\SalaryStructures\SalaryStructureResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PayrollRegisters;
use Filament\Actions\Action;

class ListSalaryStructures extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = SalaryStructureResource::class;

    public static function registerKey(): string
    {
        return 'salary_structures';
    }

    public static function registerReports(): array
    {
        return ['ctc_register', 'employees_without_salary'];
    }

    protected function getHeaderActions(): array
    {
        // HROne parity PO-1: views, the CTC reports and the salary upload (the register's "IMPORTS"). New structures are
        // set up in the salary structure builder.
        return [...$this->registerToolActions(), PayrollRegisters::uploads(['salary']),
            Action::make('create')->label('New salary structure')->icon('heroicon-m-plus')->url(SalaryStructureBuilder::getUrl())->visible(fn () => SalaryStructureBuilder::canAccess())];
    }
}
