<?php

namespace App\Filament\Resources\RatingScales\Pages;

use App\Filament\Resources\RatingScales\RatingScaleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRatingScales extends ManageRecords
{
    protected static string $resource = RatingScaleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
