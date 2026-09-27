<?php

namespace App\Filament\Resources\ProfitCentres\Pages;

use App\Filament\Resources\ProfitCentres\ProfitCentreResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProfitCentres extends ListRecords
{
    protected static string $resource = ProfitCentreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
