<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Support\AuditReasonField;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->withAuditReason(AuditReasonField::extract($data))->update($data);

        return $record;
    }
}
