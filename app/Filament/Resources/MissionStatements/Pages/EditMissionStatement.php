<?php

namespace App\Filament\Resources\MissionStatements\Pages;

use App\Filament\Resources\MissionStatements\MissionStatementResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleEditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMissionStatement extends PeopleEditRecord
{
    protected static string $resource = MissionStatementResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->withAuditReason(AuditReasonField::extract($data))->update($data);

        return $record;
    }
}
