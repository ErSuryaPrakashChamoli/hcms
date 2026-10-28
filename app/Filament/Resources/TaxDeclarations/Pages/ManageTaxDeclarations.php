<?php

namespace App\Filament\Resources\TaxDeclarations\Pages;

use App\Filament\Pages\DeclareInvestments;
use App\Filament\Resources\TaxDeclarations\TaxDeclarationResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;

class ManageTaxDeclarations extends PeopleManageRecords
{
    protected static string $resource = TaxDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('declare')->label('Declare investments')->icon('heroicon-m-clipboard-document-check')
                ->visible(fn () => DeclareInvestments::canAccess())
                ->url(fn () => DeclareInvestments::getUrl()),
        ];
    }
}
