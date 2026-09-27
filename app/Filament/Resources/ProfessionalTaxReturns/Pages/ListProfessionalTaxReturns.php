<?php

namespace App\Filament\Resources\ProfessionalTaxReturns\Pages;

use App\Domain\Compliance\Services\Returns\ProfessionalTaxReturns;
use App\Filament\Resources\ProfessionalTaxReturns\ProfessionalTaxReturnResource;
use App\Filament\Support\GenerateMonthlyReturnAction;
use Filament\Resources\Pages\ListRecords;

class ListProfessionalTaxReturns extends ListRecords
{
    protected static string $resource = ProfessionalTaxReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [GenerateMonthlyReturnAction::make(ProfessionalTaxReturns::class, 'Generate PT return')];
    }
}
