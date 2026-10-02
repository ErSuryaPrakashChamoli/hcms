<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Domain\Communication\Services\Communications;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Support\AudienceCriteriaSchema;
use App\Filament\Support\ServiceDeskActions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    /** Drafts only: a submitted announcement never changes (a correction is a new version). */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return DB::transaction(function () use ($record, $data) {
                $comms = app(Communications::class);
                $comms->update($record, ['audience_criteria' => AudienceCriteriaSchema::clean($data['audience_criteria'] ?? [])] + $data, auth()->user());
                if (filled($data['attachment_upload'] ?? null)) {
                    $comms->attach($record, (string) $data['attachment_upload'], $data['attachment_upload_name'] ?? null, auth()->user());
                }

                return $record;
            });
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
    }
}
