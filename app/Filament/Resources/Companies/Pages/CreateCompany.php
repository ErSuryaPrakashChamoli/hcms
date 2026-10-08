<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateCompany extends PeopleCreateRecord
{
    protected static string $resource = CompanyResource::class;
}
