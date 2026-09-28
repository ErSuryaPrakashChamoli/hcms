<?php

namespace App\Filament\Resources\DevelopmentNeeds\Pages;

use App\Filament\Resources\DevelopmentNeeds\DevelopmentNeedResource;
use Filament\Resources\Pages\ManageRecords;

class ManageDevelopmentNeeds extends ManageRecords
{
    protected static string $resource = DevelopmentNeedResource::class;

    protected function getHeaderActions(): array
    {
        return DevelopmentNeedResource::headerActions();
    }
}
