<?php

namespace App\Filament\Resources\FeedbackEntries\Pages;

use App\Filament\Resources\FeedbackEntries\FeedbackEntryResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageFeedbackEntries extends PeopleManageRecords
{
    protected static string $resource = FeedbackEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [FeedbackEntryResource::giveAction(), FeedbackEntryResource::requestAction()];
    }
}
