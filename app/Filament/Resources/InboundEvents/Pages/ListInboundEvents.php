<?php

namespace App\Filament\Resources\InboundEvents\Pages;

use App\Filament\Resources\InboundEvents\InboundEventResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListInboundEvents extends PeopleListRecords
{
    protected static string $resource = InboundEventResource::class;
}
