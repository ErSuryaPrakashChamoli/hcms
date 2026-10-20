<?php

namespace App\Filament\Resources\EsiReturns\Pages;

use App\Domain\Compliance\Services\Returns\EsiReturns;
use App\Filament\Resources\EsiReturns\EsiReturnResource;
use App\Filament\Support\GenerateMonthlyReturnAction;
use App\Filament\Support\Pages\PeopleListRecords;

class ListEsiReturns extends PeopleListRecords
{
    protected static string $resource = EsiReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [GenerateMonthlyReturnAction::make(EsiReturns::class, 'Generate ESI return')];
    }
}
