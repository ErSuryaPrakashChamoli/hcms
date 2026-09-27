<?php

namespace App\Filament\Resources\OneOnOnes\Pages;

use App\Filament\Resources\OneOnOnes\OneOnOneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOneOnOnes extends ManageRecords
{
    protected static string $resource = OneOnOneResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Schedule')];
    }
}
