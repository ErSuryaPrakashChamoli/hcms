<?php

namespace App\Filament\Resources\LwfReturns\Pages;

use App\Domain\Compliance\Services\Returns\LwfReturns;
use App\Filament\Resources\LwfReturns\LwfReturnResource;
use App\Filament\Support\GenerateMonthlyReturnAction;
use App\Filament\Support\Pages\PeopleListRecords;

class ListLwfReturns extends PeopleListRecords
{
    protected static string $resource = LwfReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [GenerateMonthlyReturnAction::make(LwfReturns::class, 'Generate LWF return')];
    }
}
