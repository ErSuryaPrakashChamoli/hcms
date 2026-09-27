<?php

namespace App\Filament\Resources\FeedbackEntries\Pages;

use App\Filament\Resources\FeedbackEntries\FeedbackEntryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageFeedbackEntries extends ManageRecords
{
    protected static string $resource = FeedbackEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [FeedbackEntryResource::giveAction(), FeedbackEntryResource::requestAction()];
    }
}
