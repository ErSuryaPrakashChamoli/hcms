<?php

namespace App\Filament\Resources\TdsProfiles\Pages;

use App\Filament\Resources\TdsProfiles\TdsProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTdsProfiles extends ManageRecords
{
    protected static string $resource = TdsProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
