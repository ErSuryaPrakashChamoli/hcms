<?php

namespace App\Filament\Resources\WorkSchedules\Pages;

use App\Filament\Resources\WorkSchedules\WorkScheduleResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateWorkSchedule extends PeopleCreateRecord
{
    protected static string $resource = WorkScheduleResource::class;
}
