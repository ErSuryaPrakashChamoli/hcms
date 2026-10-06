<?php

namespace App\Filament\Resources\Tenants\Pages;

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
        unset($data['status']);
        $record->withAuditReason(AuditReasonField::extract($data))->update($data);

        return $record;
    }
}
