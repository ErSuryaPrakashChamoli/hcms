<?php

namespace App\Filament\Resources\PublishedDocuments\Pages;

use App\Filament\Resources\PublishedDocuments\PublishedDocumentResource;
use App\Filament\Support\DocumentShareActions;
use App\Filament\Support\Pages\PeopleListRecords;

class ListPublishedDocuments extends PeopleListRecords
{
    protected static string $resource = PublishedDocumentResource::class;

    public function getSubheading(): ?string
    {
        return 'Every document shared in bulk, per employee. Unpublishing hides a document from the employee; it is kept with its file.';
    }

    protected function getHeaderActions(): array
    {
        return [DocumentShareActions::share()];
    }
}
