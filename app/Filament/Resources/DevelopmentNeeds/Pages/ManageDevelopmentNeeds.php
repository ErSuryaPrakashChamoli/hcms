<?php

namespace App\Filament\Resources\DevelopmentNeeds\Pages;

use App\Filament\Resources\DevelopmentNeeds\DevelopmentNeedResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageDevelopmentNeeds extends PeopleManageRecords
{
    protected static string $resource = DevelopmentNeedResource::class;

    protected function getHeaderActions(): array
    {
        return DevelopmentNeedResource::headerActions();
    }
}
