<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Support\AuditReasonField;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->withAuditReason(AuditReasonField::extract($data))->update($data);

        return $record;
    }
}
