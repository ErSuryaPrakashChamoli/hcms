<?php

namespace App\Filament\Resources\Letters\Pages;

use App\Domain\Letters\Services\LetterDownloads;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\LetterSchedules\LetterScheduleResource;
use App\Filament\Support\BulkRecordActions;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListLetters extends PeopleListRecords
{
    use HasRegisterTools;

    /** HROne parity WO-13: "Generate & publish" on letters not yet issued (selected rows or all matching). */
    public const BULK_OPERATIONS = ['letters.publish'];

    /** HROne parity WO-13: the process letter registers HROne keeps per type (§23.7.1 #1, #22–27), as views. */
    private const PROCESS_TYPES = [
        'probation_extension' => 'Extension letters', 'termination' => 'Termination letters', 'contract' => 'Contract letters',
        'transfer_intimation' => 'Transfer intimation letters', 'resignation_acceptance' => 'Resignation acceptance letters',
    ];

    protected static string $resource = LetterResource::class;

    public static function registerKey(): string
    {
        return 'letters';
    }

    /** HROne parity WO-1: the Workforce letters registers as views of the one Letters register (by type). */
    public static function presetViews(): array
    {
        return collect(['confirmation', 'transfer', 'relieving', 'experience', 'appointment', 'warning'])
            ->filter(fn (string $type) => array_key_exists($type, config('peopleos.letters.types')))
            ->mapWithKeys(fn (string $type) => [$type => ['label' => config("peopleos.letters.types.{$type}").' letters', 'filters' => ['type' => ['value' => $type]]]])
            ->put('pending', ['label' => 'Waiting for approval', 'filters' => ['status' => ['value' => 'pending_approval']]])
            // HROne parity GO-1: the Performance panel's letter registers (performance, PIP and PIP evaluation letters);
            // PO-1: the Payroll panel's "Salary revision letters" (increment letters).
            ->merge(collect(['performance' => 'Performance letters', 'pip' => 'PIP letters', 'pip_outcome' => 'PIP evaluation letters', 'increment' => 'Salary revision letters'])
                ->filter(fn (string $label, string $type) => array_key_exists($type, config('peopleos.letters.types')))
                ->map(fn (string $label, string $type) => ['label' => $label, 'filters' => ['type' => ['value' => $type]]]))
            // HROne parity WO-13: the remaining process letter registers (with the process and who generated each letter), the
            // letters approved and waiting to be issued (the generation queue), and revoked letters.
            ->merge(collect(self::PROCESS_TYPES)
                ->filter(fn (string $label, string $type) => array_key_exists($type, config('peopleos.letters.types')))
                ->map(fn (string $label, string $type) => ['label' => $label, 'filters' => ['type' => ['value' => $type]], 'columns' => ['process', 'requester.name']]))
            ->put('to_issue', ['label' => 'Ready to issue', 'filters' => ['status' => ['value' => 'approved']], 'columns' => ['process', 'requester.name']])
            ->put('revoked', ['label' => 'Revoked letters', 'filters' => ['status' => ['value' => 'revoked']]])
            ->all();
    }

    public static function registerReports(): array
    {
        return ['letters_issued_this_month', 'letters_pending'];
    }

    public static function registerBulkOperations(): array
    {
        return self::BULK_OPERATIONS;
    }

    protected function getHeaderActions(): array
    {
        return [...$this->registerToolActions(), $this->downloadMatching(), $this->schedules(), LetterResource::generate()];
    }

    /**
     * HROne parity WO-13 ("In order to download letter in bulk, apply a status filter as published"): every issued letter
     * matching the register's filters and search, as one zip prepared in the background.
     */
    private function downloadMatching(): Action
    {
        return Action::make('downloadMatching')->label('Download letters')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn () => LetterResource::readsLetters() && LetterDownloads::mayDownload(auth()->user()))
            ->modalHeading('Download the issued letters matching the filters')
            ->modalSubmitActionLabel('Prepare the zip')
            ->schema(fn () => [Text::make(BulkRecordActions::isNarrowed($this)
                ? 'Issued letters matching the register\'s filters and search right now: '.$this->issuedMatching()->count().' (at most '.LetterDownloads::max().'). Only letters you may open go in; each is recorded as downloaded. You are notified when the zip is ready.'
                : 'Apply a filter or a search first (for example a type and a generated date range), so the zip holds the letters you mean.')])
            ->action(function () {
                if (! BulkRecordActions::isNarrowed($this)) {
                    Notification::make()->warning()->title('Apply a filter or a search first')->send();

                    return;
                }
                $ids = $this->issuedMatching()->limit(LetterDownloads::max() + 1)->pluck('letters.id')->all();
                if ($ids === []) {
                    Notification::make()->warning()->title('No issued letter matches the filters')->send();

                    return;
                }
                LetterResource::requestDownload($ids, 'all_matching');
            });
    }

    private function issuedMatching(): Builder
    {
        return $this->getFilteredTableQuery()->where('letters.status', 'issued');
    }

    private function schedules(): Action
    {
        return Action::make('schedules')->label('Letter schedules')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
            ->visible(fn () => LetterScheduleResource::canViewAny())
            ->url(fn () => LetterScheduleResource::getUrl('index'));
    }
}
