<?php

namespace App\Filament\Resources\TalentReviews\Pages;

use App\Filament\Resources\TalentReviews\TalentReviewResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageTalentReviews extends PeopleManageRecords
{
    protected static string $resource = TalentReviewResource::class;

    protected function getHeaderActions(): array
    {
        return TalentReviewResource::headerActions();
    }
}
