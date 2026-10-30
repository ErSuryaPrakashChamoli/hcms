<?php

namespace App\Filament\Resources\FeedbackRounds\Pages;

use App\Filament\Resources\FeedbackRounds\FeedbackRoundResource;
use App\Filament\Support\Feedback360Actions;
use App\Filament\Support\Pages\PeopleListRecords;

class ListFeedbackRounds extends PeopleListRecords
{
    protected static string $resource = FeedbackRoundResource::class;

    protected function getHeaderActions(): array
    {
        return [Feedback360Actions::launch()];
    }
}
