<?php

namespace App\Filament\Resources\InboundEvents\Pages;

use App\Filament\Resources\InboundEvents\InboundEventResource;
use Filament\Resources\Pages\ListRecords;

class ListInboundEvents extends ListRecords
{
    protected static string $resource = InboundEventResource::class;
}
