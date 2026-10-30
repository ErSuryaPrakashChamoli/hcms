<?php

namespace App\Filament\Support;

use App\Domain\Configuration\Models\Policy;
use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Models\FeedbackRound;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Feedback360Rules;
use App\Domain\Performance\Services\FeedbackNominations;
use App\Domain\Performance\Services\FeedbackRoundResults;
use App\Domain\Performance\Services\FeedbackRounds;
use App\Filament\Resources\FeedbackRequests\FeedbackRequestResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HROne setup parity HP-8h: the actions of the 360 feedback screens (rounds, nomination sets, feedback to give). Every
 * action calls the domain services, which check again; results are shown only through FeedbackRoundResults.
 */
final class Feedback360Actions
{
    /** Launch a round (HR): the policy, the period containing a day (or dates), the population or chosen employees. */
    public static function launch(): Action
    {
        return Action::make('launchRound')->label('Launch 360 feedback')->icon(Heroicon::OutlinedRocketLaunch)->color('primary')
            ->visible(fn () => FeedbackRounds::mayManage(auth()->user()))
            ->schema([
                Select::make('policy_id')->label('360 feedback policy')->required()->native(false)
                    ->options(fn () => Policy::query()->where('type', 'feedback_360')->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('name')->label('Round name (blank = policy and period)')->maxLength(160),
                DatePicker::make('day')->label('Period containing (the policy\'s frequency decides the period)')->native(false)->default(today()),
                DatePicker::make('window_closes_on')->label('Window closes on (blank = from the policy)')->native(false),
                Select::make('employee_ids')->label('Employees (blank = everyone under the policy)')->multiple()->searchable()
                    ->options(fn () => Employee::query()->with('person')->employed()->orderBy('employee_code')->get()->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
                Select::make('performance_cycle_id')->label('Performance cycle the 360 rating may blend into (optional)')->native(false)
                    ->options(fn () => PerformanceCycle::query()->whereIn('status', ['draft', 'scheduled', 'active'])->orderByDesc('period_start')->pluck('name', 'id')->all()),
            ])
            ->action(fn (array $data) => PerformanceActions::run(fn () => app(FeedbackRounds::class)->launch(Policy::query()->findOrFail($data['policy_id']), $data, auth()->user()),
                fn (FeedbackRound $round) => '360 feedback launched for '.$round->participants()->count().' employee(s)'));
    }

    /** @return list<Action> extend, close, cancel and export a round (HR) */
    public static function forRound(): array
    {
        return [
            Action::make('extend')->label('Extend window')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                ->visible(fn (FeedbackRound $record) => $record->isOpen() && FeedbackRounds::mayManage(auth()->user()))
                ->schema([DatePicker::make('closes_on')->label('New closing date')->required()->native(false), Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (FeedbackRound $record, array $data) => PerformanceActions::run(fn () => app(FeedbackRounds::class)->extend($record, (string) $data['closes_on'], (string) $data['reason'], auth()->user()), 'Window extended')),
            Action::make('close')->label('Close round')->icon(Heroicon::OutlinedLockClosed)->color('warning')->requiresConfirmation()
                ->modalDescription('Answers stop; employees and managers may read the results as the policy says. Clients\' links stop working.')
                ->visible(fn (FeedbackRound $record) => $record->isOpen() && FeedbackRounds::mayManage(auth()->user()))
                ->action(fn (FeedbackRound $record) => PerformanceActions::run(fn () => app(FeedbackRounds::class)->close($record, auth()->user(), 'Closed by HR'), 'Round closed')),
            Action::make('cancelRound')->label('Cancel round')->icon(Heroicon::OutlinedXMark)->color('danger')
                ->visible(fn (FeedbackRound $record) => $record->isOpen() && FeedbackRounds::mayManage(auth()->user()))
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (FeedbackRound $record, array $data) => PerformanceActions::run(fn () => app(FeedbackRounds::class)->cancel($record, (string) $data['reason'], auth()->user()), 'Round cancelled')),
            Action::make('export')->label('Export results')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn () => FeedbackRoundResults::isHr(auth()->user()))
                ->action(fn (FeedbackRound $record) => self::export($record)),
        ];
    }

    /** CSV of the aggregated results (anonymity applied; never single answers or text). */
    public static function export(FeedbackRound $round): StreamedResponse
    {
        $rows = app(FeedbackRoundResults::class)->exportRows($round, auth()->user());

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee code', 'Employee', 'Source', 'Anonymous', 'Asked', 'Answered', 'Average score %', 'Note']);
            foreach ($rows as $row) {
                fputcsv($out, array_values($row));
            }
            fclose($out);
        }, 'feedback-360-'.$round->id.'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return list<Action> nominate, submit, approve / send back and read the results of a nomination set */
    public static function forParticipant(): array
    {
        $service = fn () => app(FeedbackNominations::class);

        return [
            Action::make('nominate')->label('Nominate reviewers')->icon(Heroicon::OutlinedUserPlus)->color('primary')
                ->visible(fn (FeedbackParticipant $record) => in_array($record->nomination_status, ['draft', 'returned'], true) && $record->round?->isOpen() && $service()->mayNominate(auth()->user(), $record))
                ->modalDescription(fn (FeedbackParticipant $record) => $record->decision_note ? 'Sent back: '.$record->decision_note : null)
                ->schema(fn (FeedbackParticipant $record) => self::nominationFields($record))
                ->fillForm(fn (FeedbackParticipant $record) => self::currentNominations($record))
                ->action(fn (FeedbackParticipant $record, array $data) => PerformanceActions::run(fn () => $service()->nominate($record, ['peer' => $data['peer'] ?? [], 'subordinate' => $data['subordinate'] ?? []], array_values((array) ($data['clients'] ?? [])), auth()->user()), 'Nominations saved')),
            Action::make('submitNominations')->label('Submit nominations')->icon(Heroicon::OutlinedPaperAirplane)->color('success')->requiresConfirmation()
                ->modalDescription(fn (FeedbackParticipant $record) => Feedback360Rules::approval((array) $record->round?->settings) === 'none' ? 'The reviewers are asked for feedback at once.' : 'The nominations wait for approval.')
                ->visible(fn (FeedbackParticipant $record) => in_array($record->nomination_status, ['draft', 'returned'], true) && $record->round?->isOpen() && $service()->mayNominate(auth()->user(), $record) && $service()->activeNominated($record)->isNotEmpty())
                ->action(fn (FeedbackParticipant $record) => PerformanceActions::run(fn () => $service()->submit($record, auth()->user()), 'Nominations submitted')),
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')
                ->visible(fn (FeedbackParticipant $record) => $service()->mayDecide(auth()->user(), $record))
                ->schema([Textarea::make('note')->maxLength(500)])
                ->action(fn (FeedbackParticipant $record, array $data) => PerformanceActions::run(fn () => $service()->approve($record, $data['note'] ?? null, auth()->user()), 'Nominations approved')),
            Action::make('sendBack')->label('Send back')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                ->visible(fn (FeedbackParticipant $record) => $service()->mayDecide(auth()->user(), $record))
                ->schema([Textarea::make('note')->label('Why?')->required()->maxLength(500)])
                ->action(fn (FeedbackParticipant $record, array $data) => PerformanceActions::run(fn () => $service()->sendBack($record, (string) $data['note'], auth()->user()), 'Nominations sent back')),
            Action::make('results')->label('Results')->icon(Heroicon::OutlinedChartBar)->color('gray')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->visible(fn (FeedbackParticipant $record) => app(FeedbackRoundResults::class)->canSee(auth()->user(), $record))
                ->modalHeading(fn (FeedbackParticipant $record) => '360 feedback · '.($record->employee?->person?->full_name ?? ''))
                ->schema(fn (FeedbackParticipant $record) => [
                    TextEntry::make('results')->hiddenLabel()->listWithLineBreaks()->placeholder('No feedback yet.')
                        ->state(self::resultLines(app(FeedbackRoundResults::class)->results($record, auth()->user()))),
                ]),
        ];
    }

    /** @return list<Action> give feedback or decline (the reviewer) */
    public static function forNomination(): array
    {
        $service = fn () => app(FeedbackNominations::class);

        return [
            Action::make('give')->label('Give feedback')->icon(Heroicon::OutlinedPencilSquare)->color('primary')
                ->visible(fn (FeedbackNomination $record) => $record->status === 'requested' && (int) $record->reviewer_user_id === (int) auth()->id() && $record->round?->windowOpen())
                ->modalHeading(fn (FeedbackNomination $record) => '360 feedback for '.($record->employee?->person?->full_name ?? ''))
                ->modalDescription(fn (FeedbackNomination $record) => $record->is_anonymous ? 'Anonymous: your answers are shown only together with others\', never with your name.' : 'Your name is shown with your answers.')
                ->schema(fn (FeedbackNomination $record) => FeedbackRequestResource::answerFields((array) ($record->round?->templateFor($record->relation)['questions'] ?? [])))
                ->action(fn (FeedbackNomination $record, array $data) => PerformanceActions::run(fn () => $service()->respond($record, (array) ($data['answers'] ?? []), auth()->user()), 'Feedback given')),
            Action::make('decline')->label('Not taking part')->icon(Heroicon::OutlinedXMark)->color('gray')->requiresConfirmation()
                ->visible(fn (FeedbackNomination $record) => $record->status === 'requested' && (int) $record->reviewer_user_id === (int) auth()->id() && $record->round?->windowOpen())
                ->action(fn (FeedbackNomination $record) => PerformanceActions::run(fn () => $service()->decline($record, auth()->user()), 'Noted: you are not taking part')),
        ];
    }

    /** @return list<Component> */
    private static function nominationFields(FeedbackParticipant $participant): array
    {
        $settings = (array) $participant->round?->settings;
        $service = app(FeedbackNominations::class);
        $fields = [];
        foreach (['peer' => 'Peers', 'subordinate' => 'Subordinates'] as $relation => $label) {
            if (! Feedback360Rules::enabled($settings, $relation)) {
                continue;
            }
            $max = Feedback360Rules::max($settings, $relation);
            $fields[] = Select::make($relation)->label($label.($max ? " (at most {$max})" : ''))->multiple()->searchable()->maxItems($max)
                ->options(fn () => $service->candidates($participant, $relation)->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all());
        }
        if (Feedback360Rules::enabled($settings, 'client')) {
            $max = Feedback360Rules::max($settings, 'client');
            $fields[] = Repeater::make('clients')->label('Clients'.($max ? " (at most {$max})" : ''))->maxItems($max)->defaultItems(0)->columns(2)->schema([
                TextInput::make('name')->label('Client name')->required()->maxLength(160),
                TextInput::make('email')->label('Email')->email()->required()->maxLength(191),
            ]);
        }

        return $fields;
    }

    /** @return array<string, mixed> the set's current choice, for the form */
    private static function currentNominations(FeedbackParticipant $participant): array
    {
        $current = app(FeedbackNominations::class)->activeNominated($participant);

        return [
            'peer' => $current->where('relation', 'peer')->pluck('reviewer_employee_id')->all(),
            'subordinate' => $current->where('relation', 'subordinate')->pluck('reviewer_employee_id')->all(),
            'clients' => $current->where('relation', 'client')->map(fn (FeedbackNomination $n) => ['name' => $n->external_name, 'email' => $n->external_email])->values()->all(),
        ];
    }

    /**
     * The results as lines: per source, how many answered and the average; per question the average, counts or comments
     * (anonymous sources: aggregated and unattributed; named sources: per reviewer).
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<string>
     */
    public static function resultLines(array $results): array
    {
        $lines = [];
        foreach ($results as $source) {
            $lines[] = $source['label'].($source['anonymous'] ? ' (anonymous)' : '').' · '.$source['given'].' of '.$source['asked'].' answered'
                .($source['score'] !== null ? ' · average '.rtrim(rtrim(number_format((float) $source['score'], 2, '.', ''), '0'), '.').'%' : '')
                .($source['note'] ? ' · '.$source['note'] : '');
            if ($source['named'] !== []) {
                foreach ($source['named'] as $named) {
                    $lines[] = '   '.$named['reviewer'].': '.implode('; ', array_map(fn (array $a) => $a['title'].' — '.$a['answer'], $named['answers']));
                }

                continue;
            }
            foreach ($source['questions'] as $question) {
                $lines[] = '   '.$question['title'].': '.match (true) {
                    array_key_exists('average', $question) => $question['average'] === null ? '—' : $question['average'].($question['out_of'] ? ' / '.$question['out_of'] : ''),
                    array_key_exists('counts', $question) => collect($question['counts'])->map(fn ($n, $label) => "{$label} {$n}")->implode(', ') ?: '—',
                    default => implode(' · ', array_map(fn ($t) => '“'.$t.'”', $question['texts'] ?? [])) ?: '—',
                };
            }
        }

        return $lines;
    }

    /** "Peers 2 / 3 · Clients 0 / 1" — answered of asked, per source (no answers). */
    public static function statusText(Model $participant): string
    {
        $labels = (array) config('peopleos.performance.feedback_360.relations');

        return collect(app(FeedbackRoundResults::class)->statusCounts($participant))
            ->map(fn (array $c, string $relation) => ($labels[$relation] ?? $relation).' '.$c['given'].' / '.$c['asked'])->implode(' · ');
    }
}
