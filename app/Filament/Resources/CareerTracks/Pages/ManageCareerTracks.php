<?php

namespace App\Filament\Resources\CareerTracks\Pages;

use App\Filament\Resources\CareerTracks\CareerTrackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCareerTracks extends ManageRecords
{
    protected static string $resource = CareerTrackResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
