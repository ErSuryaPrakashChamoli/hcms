<?php

namespace App\Filament\Resources\ProfessionalTaxReturns\Pages;

use App\Domain\Compliance\Services\Returns\ProfessionalTaxReturns;
use App\Filament\Resources\ProfessionalTaxReturns\ProfessionalTaxReturnResource;
use App\Filament\Support\GenerateMonthlyReturnAction;
use App\Filament\Support\Pages\PeopleListRecords;

class ListProfessionalTaxReturns extends PeopleListRecords
{
    protected static string $resource = ProfessionalTaxReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [GenerateMonthlyReturnAction::make(ProfessionalTaxReturns::class, 'Generate PT return')];
    }
}
