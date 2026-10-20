<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Domain\Identity\Services\RoleGrants;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateRole extends PeopleCreateRecord
{
    protected static string $resource = RoleResource::class;

    /** SaaS.11: a new role's permissions are audited with it (ROLE_CHANGED). */
    protected function afterCreate(): void
    {
        app(RoleGrants::class)->recordPermissionChange($this->getRecord(), [], auth()->user(), 'New role');
    }
}
