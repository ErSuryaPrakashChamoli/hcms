<?php

namespace App\Filament\Resources\Surveys\RelationManagers;

use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Models\SurveyQuestion;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Services\Surveys;
use App\Filament\Pages\SurveyResults;
use App\Filament\Resources\Surveys\SurveyResource;
use App\Filament\Support\AudienceCriteriaSchema;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13: a survey's versions and their lifecycle. Only a draft is edited (settings and
 * questions); it is submitted, approved by a second person, published, opened on its date, closed and
 * archived. A correction is a new version: responses stay pinned to the version they answered.
 */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', SurveyVersion::class) ?? false;
    }

    public function table(Table $table): Table
    {
        $run = fn (callable $callback, string $done) => ServiceDeskActions::run($callback, $done);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('questions'))
            ->columns([
                TextColumn::make('version')->label('v')->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.engagement.statuses.{$state}", $state))->color(fn (string $state) => SurveyResource::statusColor($state)),
                TextColumn::make('anonymity_mode')->label('Responses')->badge()->color(fn (string $state) => $state === 'anonymous' ? 'success' : ($state === 'confidential' ? 'warning' : 'gray')),
                TextColumn::make('questions_count')->label('Questions'),
                TextColumn::make('breakdown_dimension')->label('Breakdown')->formatStateUsing(fn (?string $state) => $state ? config("peopleos.engagement.breakdown_dimensions.{$state}") : '—')->placeholder('Overall only'),
                TextColumn::make('audience')->label('Audience')->state(fn (SurveyVersion $record) => $record->audience_id ? 'Audience: '.Audience::query()->whereKey($record->audience_id)->value('name') : AudienceCriteriaSchema::describe($record->audience_criteria))->wrap(),
                TextColumn::make('opens_at')->label('Opens')->dateTime()->placeholder('On publication'),
                TextColumn::make('closes_at')->label('Closes')->dateTime(),
                TextColumn::make('eligible_count')->label('Eligible')->placeholder('—'),
            ])
            ->defaultSort('version', 'desc')
            ->headerActions([
                Action::make('newVersion')->label('New version')->icon('heroicon-m-document-duplicate')
                    ->visible(fn () => auth()->user()->can('engagement.manage'))
                    ->requiresConfirmation()->modalDescription('Copies the latest version (settings and questions) into a new draft. Earlier responses stay with their version.')
                    ->action(fn () => $run(fn () => app(Surveys::class)->newVersion($this->getOwnerRecord(), auth()->user()), 'Draft version created')),
            ])
            ->recordActions([
                Action::make('results')->label('Results')->icon('heroicon-m-chart-bar')->color('gray')
                    ->visible(fn (SurveyVersion $record) => in_array($record->status, ['open', 'closed', 'archived'], true) && (auth()->user()->can('engagement.analytics') || auth()->user()->can('engagement.team_results')))
                    ->url(fn (SurveyVersion $record) => SurveyResults::getUrl(['version' => $record->id])),
                ActionGroup::make([
                    Action::make('settings')->label('Edit settings')->icon('heroicon-m-pencil-square')
                        ->visible(fn (SurveyVersion $record) => $record->status === 'draft' && auth()->user()->can('engagement.manage'))
                        ->fillForm(fn (SurveyVersion $record) => $record->only(['intro', 'anonymity_mode', 'response_rule', 'response_period', 'breakdown_dimension', 'audience_id', 'opens_at', 'closes_at'])
                            + ['audience_criteria' => $record->audience_criteria ?? [], 'visibility' => array_keys(array_filter($record->result_visibility ?? ['hr' => true])), 'reminder_days' => $record->reminder_policy['after_days'] ?? [3],
                                'closing_days_before' => $record->reminder_policy['closing_days_before'] ?? 2, 'max_reminders' => $record->reminder_policy['max'] ?? 2])
                        ->schema(self::settingsSchema())
                        ->action(fn (SurveyVersion $record, array $data) => $run(fn () => app(Surveys::class)->updateDraft($record, [
                            ...array_intersect_key($data, array_flip(['intro', 'anonymity_mode', 'response_rule', 'response_period', 'breakdown_dimension', 'audience_id', 'opens_at', 'closes_at'])),
                            'audience_criteria' => AudienceCriteriaSchema::clean($data['audience_criteria'] ?? []),
                            'result_visibility' => collect(['hr', 'managers', 'employees'])->mapWithKeys(fn ($k) => [$k => in_array($k, $data['visibility'] ?? [], true)])->all(),
                            'reminder_policy' => ['after_days' => array_map('intval', (array) ($data['reminder_days'] ?? [])), 'closing_days_before' => $data['closing_days_before'] ?? null, 'max' => $data['max_reminders'] ?? 2],
                        ], auth()->user()), 'Draft saved')),
                    Action::make('questions')->label('Questions')->icon('heroicon-m-queue-list')
                        ->visible(fn (SurveyVersion $record) => auth()->user()->can('engagement.view') || auth()->user()->can('engagement.manage'))
                        ->modalSubmitAction(fn (Action $action, SurveyVersion $record) => $record->status === 'draft' && auth()->user()->can('engagement.manage') ? $action : false)
                        ->fillForm(fn (SurveyVersion $record) => ['questions' => $record->questions->map(fn (SurveyQuestion $q) => [
                            'id' => $q->id, 'key' => $q->key, 'type' => $q->type, 'prompt' => $q->prompt, 'help' => $q->help, 'required' => $q->required,
                            'options' => collect($q->options ?? [])->pluck('label')->all(), 'scale_min' => $q->scale['min'] ?? null, 'scale_max' => $q->scale['max'] ?? null,
                            'admin_metadata' => $q->admin_metadata ?? [], 'analysis_tags' => $q->analysis_tags ?? [],
                        ])->all()])
                        ->schema([
                            Repeater::make('questions')->label('')->orderColumn(false)->reorderable()->collapsible()->itemLabel(fn (array $state) => ($state['key'] ?? '').' — '.($state['prompt'] ?? ''))
                                ->disabled(fn (SurveyVersion $record) => $record->status !== 'draft')
                                ->schema([
                                    TextInput::make('id')->hidden(),
                                    TextInput::make('key')->required()->maxLength(64)->helperText('Stable key: answers and trends follow it across versions.'),
                                    Select::make('type')->options(config('peopleos.engagement.question_types'))->required()->live(),
                                    Toggle::make('required'),
                                    Textarea::make('prompt')->required()->rows(2)->columnSpanFull(),
                                    TextInput::make('help')->columnSpanFull(),
                                    TagsInput::make('options')->label('Options')->visible(fn ($get) => in_array($get('type'), ['single_choice', 'multiple_choice'], true))->columnSpanFull(),
                                    TextInput::make('scale_min')->numeric()->visible(fn ($get) => in_array($get('type'), ['rating', 'number'], true)),
                                    TextInput::make('scale_max')->numeric()->visible(fn ($get) => in_array($get('type'), ['rating', 'number'], true)),
                                    KeyValue::make('admin_metadata')->label('Administrator metadata (never shown to employees)')->columnSpanFull(),
                                    TagsInput::make('analysis_tags')->label('Analysis tags (never shown to employees)')->columnSpanFull(),
                                ])->columns(3),
                        ])
                        ->action(fn (SurveyVersion $record, array $data) => $run(fn () => $this->saveQuestions($record, $data['questions'] ?? []), 'Questions saved')),
                    Action::make('submit')->label('Submit for approval')->icon('heroicon-m-paper-airplane')->requiresConfirmation()
                        ->modalDescription('Content, audience and privacy settings are frozen from now on; a change needs a new version.')
                        ->visible(fn (SurveyVersion $record) => $record->status === 'draft' && auth()->user()->can('engagement.manage'))
                        ->action(fn (SurveyVersion $record) => $run(fn () => app(Surveys::class)->submit($record, auth()->user()), 'Submitted for approval')),
                    Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')
                        ->visible(fn (SurveyVersion $record) => $record->status === 'in_review' && ! $record->workflow_instance_id && auth()->user()->can('engagement.approve') && (int) $record->prepared_by !== (int) auth()->id())
                        ->schema([Textarea::make('note')->label('Note (optional)')])
                        ->action(fn (SurveyVersion $record, array $data) => $run(fn () => app(Surveys::class)->approve($record, $data['note'] ?? null, auth()->user()), 'Version approved')),
                    Action::make('return')->label('Return to draft')->icon('heroicon-m-arrow-uturn-left')
                        ->visible(fn (SurveyVersion $record) => in_array($record->status, ['in_review', 'approved'], true) && ! $record->workflow_instance_id && auth()->user()->can('engagement.approve'))
                        ->schema([Textarea::make('note')->required()])
                        ->action(fn (SurveyVersion $record, array $data) => $run(fn () => app(Surveys::class)->returnToDraft($record, $data['note'], auth()->user()), 'Returned to draft')),
                    Action::make('publish')->label('Publish')->icon('heroicon-m-calendar')->color('success')->requiresConfirmation()
                        ->modalDescription('Schedules the version for its opening date. On opening, the audience is snapshotted and invitations are sent.')
                        ->visible(fn (SurveyVersion $record) => $record->status === 'approved' && (auth()->user()->can('engagement.manage') || auth()->user()->can('engagement.approve')))
                        ->action(fn (SurveyVersion $record) => $run(fn () => app(Surveys::class)->publish($record, auth()->user()), 'Published')),
                    Action::make('close')->label('Close now')->icon('heroicon-m-lock-closed')->color('warning')->requiresConfirmation()
                        ->visible(fn (SurveyVersion $record) => $record->status === 'open' && (auth()->user()->can('engagement.manage') || auth()->user()->can('engagement.approve')))
                        ->schema([Textarea::make('reason')->label('Reason (optional)')])
                        ->action(fn (SurveyVersion $record, array $data) => $run(fn () => app(Surveys::class)->close($record, auth()->user(), $data['reason'] ?? null), 'Survey closed')),
                    Action::make('archive')->label('Archive')->icon('heroicon-m-archive-box')->color('danger')
                        ->visible(fn (SurveyVersion $record) => in_array($record->status, ['draft', 'in_review', 'approved', 'scheduled', 'closed'], true) && (auth()->user()->can('engagement.manage') || auth()->user()->can('engagement.approve')))
                        ->schema([Textarea::make('reason')->label('Reason (required unless closed)')])
                        ->action(fn (SurveyVersion $record, array $data) => $run(fn () => app(Surveys::class)->archive($record, $data['reason'] ?? null, auth()->user()), 'Version archived')),
                ]),
            ]);
    }

    /** Replace the draft's questions with the edited list (add, change, remove), in one transaction. */
    private function saveQuestions(SurveyVersion $version, array $rows): void
    {
        $surveys = app(Surveys::class);
        DB::transaction(function () use ($version, $rows, $surveys) {
            $keep = [];
            foreach (array_values($rows) as $position => $row) {
                $existing = filled($row['id'] ?? null) ? SurveyQuestion::query()->where('survey_version_id', $version->id)->find($row['id']) : null;
                $question = $surveys->saveQuestion($version, [
                    'key' => $row['key'] ?? '', 'type' => $row['type'] ?? '', 'prompt' => $row['prompt'] ?? '', 'help' => $row['help'] ?? null, 'required' => (bool) ($row['required'] ?? false),
                    'options' => $row['options'] ?? [], 'scale' => ['min' => $row['scale_min'] ?? null, 'max' => $row['scale_max'] ?? null],
                    'admin_metadata' => ($row['admin_metadata'] ?? []) ?: null, 'analysis_tags' => ($row['analysis_tags'] ?? []) ?: null, 'position' => $position + 1,
                ], auth()->user(), $existing);
                if ($existing) {
                    $question->update(['position' => $position + 1]);
                }
                $keep[] = $question->id;
            }
            SurveyQuestion::query()->where('survey_version_id', $version->id)->whereNotIn('id', $keep)->get()->each(fn (SurveyQuestion $q) => $surveys->removeQuestion($q, auth()->user()));
        });
    }

    /** @return list<mixed> */
    private static function settingsSchema(): array
    {
        return [
            Section::make('Responses and privacy')->columns(3)->schema([
                Select::make('anonymity_mode')->options(config('peopleos.engagement.anonymity_modes'))->required()->live()->columnSpan(2),
                Select::make('response_rule')->options(config('peopleos.engagement.response_rules'))->required()->live()->helperText('Anonymous and confidential: one response.'),
                Select::make('response_period')->options(config('peopleos.engagement.response_periods'))->visible(fn ($get) => $get('response_rule') === 'per_period'),
                Select::make('breakdown_dimension')->label('Results breakdown (one dimension, pinned)')->options(config('peopleos.engagement.breakdown_dimensions'))->placeholder('Overall only')
                    ->helperText('Groups below the privacy threshold are merged or suppressed.'),
                Select::make('visibility')->label('Results visible to')->multiple()->options(['hr' => 'HR analysts (in scope)', 'managers' => 'Managers — own team (needs the line-manager breakdown)', 'employees' => 'Participants — overall only']),
            ]),
            Section::make('Dates and reminders')->columns(4)->schema([
                DateTimePicker::make('opens_at')->native(false)->placeholder('On publication'),
                DateTimePicker::make('closes_at')->native(false)->required(),
                TagsInput::make('reminder_days')->label('Remind after (days)')->placeholder('3'),
                TextInput::make('closing_days_before')->label('Closing reminder (days before)')->numeric()->minValue(0),
                TextInput::make('max_reminders')->label('Maximum reminders')->numeric()->minValue(0)->maxValue(3),
            ]),
            Section::make('Audience')->schema([
                Select::make('audience_id')->label('Saved audience')->placeholder('— use criteria below —')->options(fn () => Audience::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                Textarea::make('intro')->label('Introduction shown to employees')->rows(2),
            ]),
            AudienceCriteriaSchema::section(),
        ];
    }
}
