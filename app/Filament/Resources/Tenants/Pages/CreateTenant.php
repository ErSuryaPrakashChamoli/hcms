<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleCreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends PeopleCreateRecord
{
    protected static string $resource = TenantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $reason = AuditReasonField::extract($data);

        return app(ProvisionTenantAction::class)->handle(
            tenantData: $data,
            // SaaS.2: no password from the operator; the first administrator is invited.
            adminData: [
                'name' => $this->data['admin_name'],
                'email' => $this->data['admin_email'],
            ],
            reason: $reason,
        );
    }
}
