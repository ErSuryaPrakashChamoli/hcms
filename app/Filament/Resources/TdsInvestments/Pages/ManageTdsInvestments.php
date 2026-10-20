<?php

namespace App\Filament\Resources\TdsInvestments\Pages;

use App\Filament\Resources\TdsInvestments\TdsInvestmentResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageTdsInvestments extends PeopleManageRecords
{
    protected static string $resource = TdsInvestmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
