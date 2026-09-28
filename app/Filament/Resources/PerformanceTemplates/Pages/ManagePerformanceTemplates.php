<?php

namespace App\Filament\Resources\PerformanceTemplates\Pages;

use App\Filament\Resources\PerformanceTemplates\PerformanceTemplateResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePerformanceTemplates extends ManageRecords
{
    protected static string $resource = PerformanceTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return PerformanceTemplateResource::headerActions();
    }
}
