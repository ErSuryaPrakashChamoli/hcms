<?php

namespace App\Filament\Resources\FeedbackNominations\Pages;

use App\Filament\Resources\FeedbackNominations\FeedbackNominationResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListFeedbackNominations extends PeopleListRecords
{
    protected static string $resource = FeedbackNominationResource::class;
}
