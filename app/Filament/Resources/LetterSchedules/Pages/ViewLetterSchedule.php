<?php

namespace App\Filament\Resources\LetterSchedules\Pages;

use App\Filament\Resources\LetterSchedules\LetterScheduleResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use Filament\Actions\EditAction;

class ViewLetterSchedule extends PeopleViewRecord
{
    protected static string $resource = LetterScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->visible(fn () => auth()->user()->can('update', $this->getRecord())), ...LetterScheduleResource::lifecycleActions()];
    }
}
