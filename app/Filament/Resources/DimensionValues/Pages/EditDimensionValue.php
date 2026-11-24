<?php

namespace App\Filament\Resources\DimensionValues\Pages;

use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Filament\Resources\DimensionValues\DimensionValueResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Masters\MasterDeleteAction;
use App\Filament\Support\Pages\PeopleEditRecord;

class EditDimensionValue extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = DimensionValueResource::class;

    protected function getHeaderActions(): array
    {
        // A value with values under it, or named on an employee's position, stays (it can be made inactive instead).
        return [MasterDeleteAction::make()->hidden(fn () => $this->getRecord()->children()->exists()
            || EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('dimension_value_id', $this->getRecord()->getKey())->exists())];
    }
}
