<?php

namespace App\Filament\Resources\ServiceSlaPolicies\Pages;

use App\Filament\Resources\ServiceSlaPolicies\ServiceSlaPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceSlaPolicies extends ListRecords
{
    protected static string $resource = ServiceSlaPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
