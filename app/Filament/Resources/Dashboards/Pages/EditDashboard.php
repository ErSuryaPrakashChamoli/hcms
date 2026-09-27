<?php

namespace App\Filament\Resources\Dashboards\Pages;

use App\Filament\Resources\Dashboards\DashboardResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDashboard extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = DashboardResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
