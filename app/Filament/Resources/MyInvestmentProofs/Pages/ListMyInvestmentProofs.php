<?php

namespace App\Filament\Resources\MyInvestmentProofs\Pages;

use App\Filament\Pages\DeclareInvestments;
use App\Filament\Resources\MyInvestmentProofs\MyInvestmentProofResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class ListMyInvestmentProofs extends PeopleListRecords
{
    protected static string $resource = MyInvestmentProofResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('declare')->label('Declare investments')->icon(Heroicon::OutlinedClipboardDocumentCheck)->color('gray')
                ->visible(fn () => DeclareInvestments::canAccess())
                ->url(fn () => DeclareInvestments::getUrl()),
        ];
    }
}
