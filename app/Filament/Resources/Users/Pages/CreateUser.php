<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Services\RoleGrants;
use App\Domain\Identity\Services\TemporaryPasswords;
use App\Filament\Resources\Users\Concerns\SavesAccessScope;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

class CreateUser extends PeopleCreateRecord
{
    use SavesAccessScope;

    protected static string $resource = UserResource::class;

    /** SaaS.2: the account starts `invited`; the invitation e-mails a temporary password and the invitee sets their own. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = app(TenantContext::class)->id();
        $data['is_platform_admin'] = false;
        $data['status'] = UserStatus::Invited;
        $data['password'] = Str::random(64);

        return $this->extractAccessScope($data);
    }

    protected function afterCreate(): void
    {
        $this->persistAccessScope($this->getRecord());
        app(RoleGrants::class)->recordChange($this->getRecord(), [], auth()->user(), 'New user');
        app(TemporaryPasswords::class)->email($this->getRecord(), auth()->user(), 'New user');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'User invited. They will receive an e-mail with a temporary password and choose their own when they sign in.';
    }
}
