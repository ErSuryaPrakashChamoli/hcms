<?php

namespace App\Filament\Resources\CareerTracks\Pages;

use App\Filament\Resources\CareerTracks\CareerTrackResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageCareerTracks extends PeopleManageRecords
{
    protected static string $resource = CareerTrackResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
