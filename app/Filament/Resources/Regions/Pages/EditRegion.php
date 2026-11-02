<?php

namespace App\Filament\Resources\Regions\Pages;

use App\Filament\Resources\Regions\RegionResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditRegion extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = RegionResource::class;

    protected function getHeaderActions(): array
    {
        // A region that branches belong to stays (it can be made inactive instead).
        return [DeleteAction::make()->hidden(fn () => $this->getRecord()->locations()->exists())];
    }
}
