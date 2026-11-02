<?php

namespace App\Filament\Resources\LetterSchedules\Pages;

use App\Domain\Letters\Services\LetterSchedules;
use App\Filament\Resources\LetterSchedules\LetterScheduleResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateLetterSchedule extends PeopleCreateRecord
{
    protected static string $resource = LetterScheduleResource::class;

    /** Written through LetterSchedules (validated, audited; a "publish now" schedule is queued at once). */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(LetterSchedules::class)->save($data, auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
