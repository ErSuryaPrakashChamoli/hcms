<?php

namespace App\Filament\Resources\PayrollRuns\Pages;

use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Filament\Support\PayrollActions;

class ViewPayrollRun extends PeopleViewRecord
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return PayrollActions::forRun();
    }
}
