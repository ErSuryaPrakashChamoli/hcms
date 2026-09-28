<?php

namespace App\Filament\Resources\PerformanceCheckIns\Pages;

use App\Filament\Resources\PerformanceCheckIns\PerformanceCheckInResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePerformanceCheckIns extends ManageRecords
{
    protected static string $resource = PerformanceCheckInResource::class;

    protected function getHeaderActions(): array
    {
        return PerformanceCheckInResource::headerActions();
    }
}
