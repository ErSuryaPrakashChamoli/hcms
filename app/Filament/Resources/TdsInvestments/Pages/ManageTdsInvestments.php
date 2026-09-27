<?php

namespace App\Filament\Resources\TdsInvestments\Pages;

use App\Filament\Resources\TdsInvestments\TdsInvestmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTdsInvestments extends ManageRecords
{
    protected static string $resource = TdsInvestmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
