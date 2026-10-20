<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\SavesCustomFields;
use Illuminate\Database\Eloquent\Model;

class EditAsset extends PeopleEditRecord
{
    use SavesCustomFields;

    protected static string $resource = AssetResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $reason = AuditReasonField::extract($data);
        $this->extractCustomFields($data);
        unset($data['status'], $data['custodian_id']); // lifecycle changes go through the actions
        $record->withAuditReason($reason)->update($data);
        $this->persistCustomFields($record, $reason);

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
