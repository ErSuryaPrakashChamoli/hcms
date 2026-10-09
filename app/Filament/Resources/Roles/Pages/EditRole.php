<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Domain\Identity\Services\RoleGrants;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditRole extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = RoleResource::class;

    /** @var list<string> */
    private array $permissionsBefore = [];

    /** SaaS.11: before validation, because Filament saves the permissions relationship while it reads the form state. */
    protected function beforeValidate(): void
    {
        $this->permissionsBefore = app(RoleGrants::class)->permissionKeysOf($this->getRecord());
    }

    protected function afterSave(): void
    {
        app(RoleGrants::class)->recordPermissionChange($this->getRecord(), $this->permissionsBefore, auth()->user(), $this->data['audit_reason'] ?? null);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
