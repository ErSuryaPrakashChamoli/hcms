<?php

namespace App\Filament\Resources\Grievances\Pages;

use App\Filament\Resources\Grievances\GrievanceResource;
use App\Filament\Support\GrievanceActions;
use Filament\Resources\Pages\ListRecords;

class ListGrievances extends ListRecords
{
    protected static string $resource = GrievanceResource::class;

    protected function getHeaderActions(): array
    {
        return [GrievanceActions::raise()];
    }
}
