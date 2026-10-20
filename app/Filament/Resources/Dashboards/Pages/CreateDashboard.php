<?php

namespace App\Filament\Resources\Dashboards\Pages;

use App\Filament\Resources\Dashboards\DashboardResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateDashboard extends PeopleCreateRecord
{
    protected static string $resource = DashboardResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['owner_id' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
