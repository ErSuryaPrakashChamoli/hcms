<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Support\AssetActions;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\SavesCustomFields;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditAsset extends PeopleEditRecord
{
    use SavesCustomFields;

    protected static string $resource = AssetResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $reason = AuditReasonField::extract($data);
        $this->extractCustomFields($data);
        unset($data['status'], $data['custodian_id']); // lifecycle changes go through the actions
        try {
            $record->withAuditReason($reason)->update($data);
        } catch (RuntimeException $e) {
            // e.g. a serial number already used in a sub type that does not allow duplicates (HROne setup parity AS-A).
            $record->refresh();
            AssetActions::refuse($e->getMessage());

            throw new Halt;
        }
        $this->persistCustomFields($record, $reason);

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
