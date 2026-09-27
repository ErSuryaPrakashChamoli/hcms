<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Support\AuditReasonField;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $reason = AuditReasonField::extract($data);

        return app(ProvisionTenantAction::class)->handle(
            tenantData: $data,
            adminData: [
                'name' => $this->data['admin_name'],
                'email' => $this->data['admin_email'],
                'password' => $this->data['admin_password'],
            ],
            reason: $reason,
        );
    }
}
