<?php

namespace App\Filament\Resources\PerformanceTemplates\Pages;

use App\Filament\Resources\PerformanceTemplates\PerformanceTemplateResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManagePerformanceTemplates extends PeopleManageRecords
{
    protected static string $resource = PerformanceTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return PerformanceTemplateResource::headerActions();
    }
}
