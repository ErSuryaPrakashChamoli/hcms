<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateRole extends PeopleCreateRecord
{
    protected static string $resource = RoleResource::class;
}
