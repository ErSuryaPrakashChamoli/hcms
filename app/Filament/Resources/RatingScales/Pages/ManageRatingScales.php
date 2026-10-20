<?php

namespace App\Filament\Resources\RatingScales\Pages;

use App\Filament\Resources\RatingScales\RatingScaleResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageRatingScales extends PeopleManageRecords
{
    protected static string $resource = RatingScaleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
