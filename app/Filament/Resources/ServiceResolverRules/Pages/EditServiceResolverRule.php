<?php

namespace App\Filament\Resources\ServiceResolverRules\Pages;

use App\Filament\Resources\ServiceResolverRules\ServiceResolverRuleResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditServiceResolverRule extends PeopleEditRecord
{
    protected static string $resource = ServiceResolverRuleResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $reason = AuditReasonField::extract($data);
        try {
            $record->withAuditReason($reason)->update($data);
        } catch (RuntimeException $e) {
            $record->refresh();
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
