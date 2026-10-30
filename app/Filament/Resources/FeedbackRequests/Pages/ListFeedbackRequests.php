<?php

namespace App\Filament\Resources\FeedbackRequests\Pages;

use App\Filament\Resources\FeedbackRequests\FeedbackRequestResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListFeedbackRequests extends PeopleListRecords
{
    protected static string $resource = FeedbackRequestResource::class;
}
