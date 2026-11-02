<?php

namespace App\Filament\Resources\LetterSchedules\Pages;

use App\Filament\Resources\LetterSchedules\LetterScheduleResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListLetterSchedules extends PeopleListRecords
{
    protected static string $resource = LetterScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Schedule letters')];
    }
}
