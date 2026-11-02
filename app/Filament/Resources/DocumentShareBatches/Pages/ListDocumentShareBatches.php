<?php

namespace App\Filament\Resources\DocumentShareBatches\Pages;

use App\Filament\Resources\DocumentShareBatches\DocumentShareBatchResource;
use App\Filament\Resources\PublishedDocuments\PublishedDocumentResource;
use App\Filament\Support\DocumentShareActions;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class ListDocumentShareBatches extends PeopleListRecords
{
    protected static string $resource = DocumentShareBatchResource::class;

    public function getSubheading(): ?string
    {
        return 'Ready-made files shared with many employees at once. Letters made in PeopleOS are published from Letters ("Share letters").';
    }

    protected function getHeaderActions(): array
    {
        return [
            DocumentShareActions::share(),
            DocumentShareActions::shareLetters(),
            DocumentShareActions::template(),
            Action::make('publishedDocuments')->label('Published documents')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->url(fn () => PublishedDocumentResource::getUrl('index')),
        ];
    }
}
