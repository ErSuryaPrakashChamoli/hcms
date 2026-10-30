<?php

namespace App\Filament\Resources\PerformanceUnits\Pages;

use App\Filament\Resources\PerformanceUnits\PerformanceUnitResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManagePerformanceUnits extends PeopleManageRecords
{
    protected static string $resource = PerformanceUnitResource::class;

    protected function getHeaderActions(): array
    {
        // Units HR adds here are organisation (admin) units.
        return [CreateAction::make()->mutateDataUsing(fn (array $data) => [...$data, 'is_admin' => true, 'created_by' => auth()->id()])];
    }
}
