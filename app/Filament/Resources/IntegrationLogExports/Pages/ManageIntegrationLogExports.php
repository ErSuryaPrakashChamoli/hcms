<?php

namespace App\Filament\Resources\IntegrationLogExports\Pages;

use App\Filament\Resources\IntegrationLogExports\IntegrationLogExportResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageIntegrationLogExports extends PeopleManageRecords
{
    protected static string $resource = IntegrationLogExportResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
