<?php

namespace App\Filament\Resources\TdsInvestments\Pages;

use App\Filament\Pages\DeclareInvestments;
use App\Filament\Resources\TdsInvestments\TdsInvestmentResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;

class ManageTdsInvestments extends PeopleManageRecords
{
    protected static string $resource = TdsInvestmentResource::class;

    protected function getHeaderActions(): array
    {
        // Items are declared section by section in the guided declaration; this screen verifies their proofs.
        return [
            Action::make('declare')->label('Declare investments')->icon('heroicon-m-clipboard-document-check')
                ->visible(fn () => DeclareInvestments::canAccess())
                ->url(fn () => DeclareInvestments::getUrl()),
        ];
    }
}
