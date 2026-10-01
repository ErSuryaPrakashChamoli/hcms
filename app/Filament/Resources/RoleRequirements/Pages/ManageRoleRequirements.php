<?php

namespace App\Filament\Resources\RoleRequirements\Pages;

use App\Filament\Resources\RoleRequirements\RoleRequirementResource;
use Filament\Resources\Pages\ManageRecords;

class ManageRoleRequirements extends ManageRecords
{
    protected static string $resource = RoleRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return RoleRequirementResource::headerActions();
    }
}
