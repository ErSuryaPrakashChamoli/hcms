<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Concerns\SavesAccessScope;
use App\Filament\Resources\Users\UserResource;
use App\Support\Tenancy\TenantContext;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use SavesAccessScope;

    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = app(TenantContext::class)->id();
        $data['is_platform_admin'] = false;

        return $this->extractAccessScope($data);
    }

    protected function afterCreate(): void
    {
        $this->persistAccessScope($this->getRecord());
    }
}
