<?php

namespace App\Filament\Resources\Dashboards\Pages;

use App\Filament\Resources\Dashboards\DashboardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDashboards extends ListRecords
{
    protected static string $resource = DashboardResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
