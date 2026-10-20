<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Domain\Platform\Services\TenantProfiles;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleEditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTenant extends PeopleEditRecord
{
    protected static string $resource = TenantResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // SaaS.2: the status never changes here, whatever the request carries (see TenantSuspensions).
        // SaaS.8: an operator and a reason, both chains (TenantProfiles).
        $reason = AuditReasonField::extract($data);

        return app(TenantProfiles::class)->update($record, $data, auth()->user(), $reason);
    }
}
