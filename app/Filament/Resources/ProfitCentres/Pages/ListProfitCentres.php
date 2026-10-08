<?php

namespace App\Filament\Resources\ProfitCentres\Pages;

use App\Filament\Resources\ProfitCentres\ProfitCentreResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListProfitCentres extends PeopleListRecords
{
    protected static string $resource = ProfitCentreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
