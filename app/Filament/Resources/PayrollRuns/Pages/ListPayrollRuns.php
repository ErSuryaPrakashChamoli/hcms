<?php

namespace App\Filament\Resources\PayrollRuns\Pages;

use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PayrollActions;

class ListPayrollRuns extends PeopleListRecords
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return [PayrollActions::openRun()];
    }
}
