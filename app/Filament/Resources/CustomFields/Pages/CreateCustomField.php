<?php

namespace App\Filament\Resources\CustomFields\Pages;

use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateCustomField extends PeopleCreateRecord
{
    protected static string $resource = CustomFieldResource::class;
}
