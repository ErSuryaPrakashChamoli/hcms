<?php

namespace App\Filament\Resources\PerformanceCheckIns\Pages;

use App\Filament\Resources\PerformanceCheckIns\PerformanceCheckInResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManagePerformanceCheckIns extends PeopleManageRecords
{
    protected static string $resource = PerformanceCheckInResource::class;

    protected function getHeaderActions(): array
    {
        return PerformanceCheckInResource::headerActions();
    }
}
