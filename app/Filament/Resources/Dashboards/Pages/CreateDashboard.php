<?php

namespace App\Filament\Resources\Dashboards\Pages;

use App\Domain\Analytics\Services\DashboardViews;
use App\Filament\Resources\Dashboards\DashboardResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateDashboard extends PeopleCreateRecord
{
    protected static string $resource = DashboardResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // HROne parity CA-10: the shared-view fields, checked (values the author can see, people of this organisation).
        return app(DashboardViews::class)->normalise($data, null, auth()->user()) + ['owner_id' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
