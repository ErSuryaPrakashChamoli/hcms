<?php

namespace App\Filament\Resources\PayrollRuns\Pages;

use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Support\PayrollActions;
use Filament\Resources\Pages\ListRecords;

class ListPayrollRuns extends ListRecords
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return [PayrollActions::openRun()];
    }
}
