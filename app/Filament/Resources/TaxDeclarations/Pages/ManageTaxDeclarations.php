<?php

namespace App\Filament\Resources\TaxDeclarations\Pages;

use App\Filament\Resources\TaxDeclarations\TaxDeclarationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTaxDeclarations extends ManageRecords
{
    protected static string $resource = TaxDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
