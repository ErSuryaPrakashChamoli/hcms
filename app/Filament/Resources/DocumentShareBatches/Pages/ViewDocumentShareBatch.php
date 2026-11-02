<?php

namespace App\Filament\Resources\DocumentShareBatches\Pages;

use App\Domain\Documents\Models\DocumentShareBatch;
use App\Filament\Resources\DocumentShareBatches\DocumentShareBatchResource;
use App\Filament\Resources\PublishedDocuments\PublishedDocumentResource;
use App\Filament\Support\DocumentShareActions;
use App\Filament\Support\Pages\PeopleViewRecord;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewDocumentShareBatch extends PeopleViewRecord
{
    protected static string $resource = DocumentShareBatchResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var DocumentShareBatch $batch */
        $batch = $this->getRecord();

        return 'Document share #'.$batch->id.': '.$batch->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            DocumentShareActions::downloadErrors(),
            DocumentShareActions::unpublishBatch(),
            Action::make('publishedDocuments')->label('Published documents')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->url(fn () => PublishedDocumentResource::getUrl('index', ['filters' => ['batch' => ['value' => $this->getRecord()->getKey()]]])),
        ];
    }
}
