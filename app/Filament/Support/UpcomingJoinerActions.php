<?php

namespace App\Filament\Support;

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Services\DocumentRules;
use App\Domain\Documents\Services\EmployeeDocumentDownloads;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\ProfileCompletionMails;
use App\Domain\Employment\Services\UpcomingJoiners;
use App\Domain\Onboarding\Support\ProfileSections;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;

/**
 * HROne parity WO-4: the upcoming-joiner actions, on the Upcoming joiners register (each row) and on the Employee 360 (Action
 * group). Each one only calls its service, which checks the rights and the joiner's state again.
 */
final class UpcomingJoinerActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [self::changeJoiningDate(), self::markNotJoining(), self::downloadDocuments(), self::profileCompletionMail()];
    }

    private static function isJoiner(?Employee $record): bool
    {
        return $record !== null && $record->lifecycle_state->isPreJoining();
    }

    public static function changeJoiningDate(): Action
    {
        return Action::make('changeJoiningDate')->label('Change joining date')->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (?Employee $record) => self::isJoiner($record) && (auth()->user()?->can('transition', $record) ?? false))
            ->modalHeading(fn (Employee $record) => 'Change the joining date of '.$record->person?->display_name)
            ->modalDescription(fn (Employee $record) => 'Expected now on '.(UpcomingJoiners::expectedDate($record)?->format('d M Y') ?? 'no date').'. The not-yet-started onboarding items, the probation end and the planned position and manager (when unchanged since the hire) move with it.')
            ->modalSubmitActionLabel('Change date')
            ->schema([
                DatePicker::make('joining_date')->label('New joining date')->native(false)->required()->minDate(now()->startOfDay()),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(3),
            ])
            ->action(fn (Employee $record, array $data) => ServiceDeskActions::run(
                fn () => app(UpcomingJoiners::class)->changeJoiningDate($record, (string) $data['joining_date'], (string) $data['reason'], auth()->user()),
                fn (array $result) => 'Joining date changed to '.UpcomingJoiners::expectedDate($result['employee'])?->format('d M Y').($result['onboarding_items'] > 0 ? ' · '.$result['onboarding_items'].' onboarding item(s) rescheduled' : ''),
            ));
    }

    public static function markNotJoining(): Action
    {
        return Action::make('markNotJoining')->label('Mark as not joining')->icon(Heroicon::OutlinedUserMinus)->color('danger')
            ->visible(fn (?Employee $record) => self::isJoiner($record) && (auth()->user()?->can('transition', $record) ?? false))
            ->modalHeading(fn (Employee $record) => $record->person?->display_name.' is not joining')
            ->modalDescription(function (Employee $record) {
                $reportees = app(UpcomingJoiners::class)->reportees($record);

                return 'Their onboarding is cancelled (open items closed, not deleted), the planned position and manager end, a sign-in is turned off and they no longer count anywhere as an employee. The record is kept for rehire checks.'
                    .($reportees > 0 ? " {$reportees} person(s) are set to report to them: change their manager." : '');
            })
            ->modalSubmitActionLabel('Mark as not joining')
            ->schema([
                Select::make('reason')->label('Why are they not joining?')->required()->native(false)->options(fn () => app(UpcomingJoiners::class)->reasons()),
                Textarea::make('note')->label('Note')->rows(3)->maxLength(500)->helperText('Required for "Other".'),
            ])
            ->action(fn (Employee $record, array $data) => ServiceDeskActions::run(
                fn () => app(UpcomingJoiners::class)->markNotJoining($record, (string) $data['reason'], $data['note'] ?? null, auth()->user()),
                'Marked as not joining',
            ));
    }

    public static function downloadDocuments(): Action
    {
        return Action::make('downloadAllDocuments')->label('Download all documents')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn (?Employee $record) => $record !== null && EmployeeDocumentDownloads::mayDownload(auth()->user()) && (auth()->user()?->can('view', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading('Download all documents')
            ->modalDescription('The documents you may open are put in one zip in the background; you are notified with a link that only you can use, for a limited time. Each document is recorded as downloaded.')
            ->modalSubmitActionLabel('Prepare the zip')
            ->action(fn (Employee $record) => ServiceDeskActions::run(
                fn () => app(EmployeeDocumentDownloads::class)->request(auth()->user(), $record),
                'Preparing the zip: you will be notified when it is ready',
            ));
    }

    public static function profileCompletionMail(): Action
    {
        return Action::make('profileCompletionMail')->label('Send profile-completion mail')->icon(Heroicon::OutlinedEnvelope)->color('gray')
            ->visible(fn (?Employee $record) => self::isJoiner($record) && ProfileCompletionMails::mayUse(auth()->user()) && (auth()->user()?->can('view', $record) ?? false))
            ->modalHeading(fn (Employee $record) => 'Ask '.$record->person?->display_name.' to complete their profile')
            ->modalSubmitActionLabel('Send')
            ->fillForm(fn (Employee $record) => [
                'sections' => array_keys(app(UpcomingJoiners::class)->missingProfileSections($record)),
                'documents' => app(DocumentRules::class)->missing($record, 'onboarding')->modelKeys(),
                'to' => ['personal'],
            ])
            ->steps([
                Step::make('What is missing')->description('Prefilled with what is not on file yet')->schema([
                    CheckboxList::make('sections')->label('Details to send')->columns(2)
                        ->options(fn () => collect((array) config('peopleos.lifecycle.joiners.profile_sections', []))->mapWithKeys(fn ($s) => [$s => ProfileSections::label((string) $s)])->all()),
                    CheckboxList::make('documents')->label('Documents to send')->columns(2)
                        ->options(fn () => DocumentType::query()->where('status', 'active')->orderBy('name')->limit(200)->pluck('name', 'id')->all()),
                ]),
                Step::make('Message')->description('Sent as plain text, with no link: joiners have no access before day one')->schema([
                    Text::make('The subject and wording come from the notification template "'.ProfileCompletionMails::TEMPLATE_KEY.'" when your organisation has one, otherwise from the standard text.'),
                    CheckboxList::make('to')->label('Send to')->required()->options(ProfileCompletionMails::RECIPIENTS),
                    Select::make('cc')->label('Copy to colleagues')->multiple()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Employee::query()->employed()->whereNotNull('work_email')
                            ->where(fn ($q) => $q->where('employee_code', 'like', "%{$search}%")->orWhereHas('person', fn ($p) => $p->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")))
                            ->with('person')->limit(20)->get()->mapWithKeys(fn (Employee $e) => [$e->id => ($e->person?->display_name ?? $e->employee_code).' · '.$e->employee_code])->all())
                        ->getOptionLabelsUsing(fn (array $values) => Employee::query()->whereKey($values)->with('person')->get()->mapWithKeys(fn (Employee $e) => [$e->id => ($e->person?->display_name ?? $e->employee_code).' · '.$e->employee_code])->all()),
                    Toggle::make('copy_to_me')->label('Send me a copy'),
                    Textarea::make('note')->label('Add a note to the mail')->rows(3)->maxLength(2000),
                ]),
            ])
            ->action(fn (Employee $record, array $data) => ServiceDeskActions::run(
                fn () => app(ProfileCompletionMails::class)->send($record, auth()->user(), (array) ($data['sections'] ?? []), (array) ($data['documents'] ?? []), (array) ($data['to'] ?? ['personal']),
                    $data['note'] ?? null, (array) ($data['cc'] ?? []), (bool) ($data['copy_to_me'] ?? false)),
                fn (int $sent) => $sent === 1 ? 'Profile-completion mail queued' : "{$sent} e-mails queued",
            ));
    }
}
