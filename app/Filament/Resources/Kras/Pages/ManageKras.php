<?php

namespace App\Filament\Resources\Kras\Pages;

use App\Filament\Resources\Kras\KraResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageKras extends PeopleManageRecords
{
    protected static string $resource = KraResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
