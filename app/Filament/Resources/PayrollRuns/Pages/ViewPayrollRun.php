<?php

namespace App\Filament\Resources\PayrollRuns\Pages;

use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Resources\PayrollRuns\Widgets\RunCockpit;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Filament\Support\PayrollActions;

class ViewPayrollRun extends PeopleViewRecord
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return PayrollActions::forRun();
    }

    /** HROne parity PO-4: the run cockpit (steps, counters, step tables, reminders, activity) above the run's details. */
    protected function getHeaderWidgets(): array
    {
        return [RunCockpit::class];
    }

    public function getWidgetData(): array
    {
        return ['runId' => $this->record->getKey()];
    }
}
