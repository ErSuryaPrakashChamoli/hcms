<?php

namespace App\Filament\Resources\LetterSchedules\Pages;

use App\Domain\Letters\Models\LetterSchedule;
use App\Domain\Letters\Services\LetterSchedules;
use App\Filament\Resources\LetterSchedules\LetterScheduleResource;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditLetterSchedule extends PeopleEditRecord
{
    protected static string $resource = LetterScheduleResource::class;

    /** The form's choices (publish now / later, before / after, saved for later) from the stored schedule. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var LetterSchedule $schedule */
        $schedule = $this->getRecord();

        return array_merge($data, [
            'publish' => $schedule->run_at === null ? 'now' : 'later',
            'offset_direction' => $schedule->offset_days < 0 ? 'before' : 'after',
            'offset_days' => abs((int) $schedule->offset_days),
            'save_for_later' => $schedule->status === 'saved',
            'signatory_ids' => array_map('strval', (array) ($schedule->signatory_ids ?? [])),
            'employee_ids' => array_map('strval', (array) ($schedule->employee_ids ?? [])),
            'audience_criteria' => (array) ($schedule->audience_criteria ?? []),
            'extra' => (array) ($schedule->extra ?? []),
            'email_to' => (array) ($schedule->email_to ?? []),
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            /** @var LetterSchedule $record */
            return app(LetterSchedules::class)->save($data, auth()->user(), $record);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
    }
}
