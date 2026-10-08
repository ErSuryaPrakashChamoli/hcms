<?php

namespace App\Filament\Resources\Shifts\Pages;

use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateShift extends PeopleCreateRecord
{
    protected static string $resource = ShiftResource::class;
}
