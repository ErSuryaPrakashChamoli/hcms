<?php

namespace App\Filament\Resources\Establishments\Pages;

use App\Filament\Resources\Establishments\EstablishmentResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageEstablishments extends PeopleManageRecords
{
    protected static string $resource = EstablishmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
