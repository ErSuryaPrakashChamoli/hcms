<?php

namespace App\Filament\Resources\Dashboards\Pages;

use App\Filament\Resources\Dashboards\DashboardResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditDashboard extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = DashboardResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
