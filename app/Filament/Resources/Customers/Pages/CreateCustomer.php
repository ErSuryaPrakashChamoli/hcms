<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateCustomer extends PeopleCreateRecord
{
    protected static string $resource = CustomerResource::class;
}
