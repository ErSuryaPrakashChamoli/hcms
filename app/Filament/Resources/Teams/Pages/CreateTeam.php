<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateTeam extends PeopleCreateRecord
{
    protected static string $resource = TeamResource::class;
}
