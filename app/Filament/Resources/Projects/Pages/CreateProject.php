<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateProject extends PeopleCreateRecord
{
    protected static string $resource = ProjectResource::class;
}
