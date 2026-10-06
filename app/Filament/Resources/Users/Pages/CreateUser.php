<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Services\UserInvitations;
use App\Filament\Resources\Users\Concerns\SavesAccessScope;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

class CreateUser extends PeopleCreateRecord
{
    use SavesAccessScope;

    protected static string $resource = UserResource::class;

    /** SaaS.2: the account starts `invited` with a password nobody knows; the invitee sets their own. */
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
        app(UserInvitations::class)->issue($this->getRecord(), auth()->user(), 'New user');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'User invited. They will receive an e-mail to set their password.';
    }
}
