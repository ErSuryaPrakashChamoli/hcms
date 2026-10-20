<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Services\TenantFirstAdministrator;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleCreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends PeopleCreateRecord
{
    protected static string $resource = TenantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $reason = AuditReasonField::extract($data);

        $tenant = app(ProvisionTenantAction::class)->handle(
            tenantData: $data,
            // SaaS.2: no password from the operator; the first administrator is invited.
            adminData: [
                'name' => $this->data['admin_name'],
                'email' => $this->data['admin_email'],
            ],
            reason: $reason,
            actor: auth()->user(),
        );

        // SaaS.8: the tenant exists even if the e-mail failed; say so, and where to send it again.
        if (app(TenantFirstAdministrator::class)->needsInvitation($tenant)) {
            Notification::make()->warning()->persistent()->title('Tenant created, but the invitation e-mail was not sent')
                ->body('Send it again from the Tenants list (Send invitation) once mail is working.')->send();
        }

        return $tenant;
    }
}
