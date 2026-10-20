<?php

namespace App\Filament\Resources\Grievances\Pages;

use App\Filament\Resources\Grievances\GrievanceResource;
use App\Filament\Support\GrievanceActions;
use App\Filament\Support\Pages\PeopleListRecords;

class ListGrievances extends PeopleListRecords
{
    protected static string $resource = GrievanceResource::class;

    protected function getHeaderActions(): array
    {
        return [GrievanceActions::raise()];
    }
}
