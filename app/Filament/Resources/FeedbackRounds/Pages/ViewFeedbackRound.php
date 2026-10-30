<?php

namespace App\Filament\Resources\FeedbackRounds\Pages;

use App\Filament\Resources\FeedbackRounds\FeedbackRoundResource;
use App\Filament\Support\Feedback360Actions;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewFeedbackRound extends PeopleViewRecord
{
    protected static string $resource = FeedbackRoundResource::class;

    protected function getHeaderActions(): array
    {
        return Feedback360Actions::forRound();
    }
}
