<?php

namespace App\Filament\Resources\BusinessRegions\Pages;

use App\Filament\Resources\BusinessRegions\BusinessRegionResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Masters\MasterDeleteAction;
use App\Filament\Support\Pages\PeopleEditRecord;

class EditBusinessRegion extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = BusinessRegionResource::class;

    protected function getHeaderActions(): array
    {
        // A business region that branches belong to stays (it can be made inactive instead).
        return [MasterDeleteAction::make()->hidden(fn () => $this->getRecord()->locations()->exists())];
    }
}
