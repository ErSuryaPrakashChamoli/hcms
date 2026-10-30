<?php

namespace App\Filament\Resources\OrganisationContacts\Pages;

use App\Filament\Resources\OrganisationContacts\OrganisationContactResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageOrganisationContacts extends PeopleManageRecords
{
    protected static string $resource = OrganisationContactResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
