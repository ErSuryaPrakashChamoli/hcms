<?php

namespace App\Filament\Resources\TalentReviews\Pages;

use App\Filament\Resources\TalentReviews\TalentReviewResource;
use Filament\Resources\Pages\ManageRecords;

class ManageTalentReviews extends ManageRecords
{
    protected static string $resource = TalentReviewResource::class;

    protected function getHeaderActions(): array
    {
        return TalentReviewResource::headerActions();
    }
}
