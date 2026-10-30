<?php

namespace App\Filament\Resources\BulkRuns\Pages;

use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListBulkRuns extends PeopleListRecords
{
    protected static string $resource = BulkRunResource::class;
}
