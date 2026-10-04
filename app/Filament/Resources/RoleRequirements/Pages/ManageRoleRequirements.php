<?php

namespace App\Filament\Resources\RoleRequirements\Pages;

use App\Filament\Resources\RoleRequirements\RoleRequirementResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageRoleRequirements extends PeopleManageRecords
{
    protected static string $resource = RoleRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return RoleRequirementResource::headerActions();
    }
}
