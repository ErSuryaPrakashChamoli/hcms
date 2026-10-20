<?php

namespace App\Filament\Resources\TaxDeclarations\Pages;

use App\Filament\Resources\TaxDeclarations\TaxDeclarationResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageTaxDeclarations extends PeopleManageRecords
{
    protected static string $resource = TaxDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
