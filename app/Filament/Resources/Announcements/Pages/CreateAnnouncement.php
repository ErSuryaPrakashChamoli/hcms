<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Domain\Communication\Services\Communications;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Support\AudienceCriteriaSchema;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CreateAnnouncement extends PeopleCreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    /** One idempotency key per form: a double submit returns the same draft. */
    public string $idempotencyKey = '';

    public function mount(): void
    {
        parent::mount();
        $this->idempotencyKey = (string) Str::uuid();
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return DB::transaction(function () use ($data) {
                $comms = app(Communications::class);
                $announcement = $comms->create(['audience_criteria' => AudienceCriteriaSchema::clean($data['audience_criteria'] ?? [])] + $data, auth()->user(), $this->idempotencyKey);
                if (filled($data['attachment_upload'] ?? null)) {
                    $comms->attach($announcement, (string) $data['attachment_upload'], $data['attachment_upload_name'] ?? null, auth()->user());
                }

                return $announcement;
            });
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
