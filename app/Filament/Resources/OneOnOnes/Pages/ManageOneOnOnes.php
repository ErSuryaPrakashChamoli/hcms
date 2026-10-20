<?php

namespace App\Filament\Resources\OneOnOnes\Pages;

use App\Filament\Resources\OneOnOnes\OneOnOneResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageOneOnOnes extends PeopleManageRecords
{
    protected static string $resource = OneOnOneResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Schedule')];
    }
}
