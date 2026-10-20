<?php

namespace App\Filament\Resources\ServiceSlaPolicies\Pages;

use App\Filament\Resources\ServiceSlaPolicies\ServiceSlaPolicyResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListServiceSlaPolicies extends PeopleListRecords
{
    protected static string $resource = ServiceSlaPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
