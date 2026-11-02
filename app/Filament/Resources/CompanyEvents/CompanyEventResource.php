<?php

namespace App\Filament\Resources\CompanyEvents;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Models\CompanyEventInvitation;
use App\Domain\Engagement\Models\CompanyEventSession;
use App\Domain\Engagement\Services\CompanyEvents;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Location;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\CompanyEvents\Pages\CreateCompanyEvent;
use App\Filament\Resources\CompanyEvents\Pages\EditCompanyEvent;
use App\Filament\Resources\CompanyEvents\Pages\ListCompanyEvents;
use App\Filament\Resources\CompanyEvents\Pages\ViewCompanyEvent;
use App\Filament\Resources\CompanyEvents\RelationManagers\InvitationsRelationManager;
use App\Filament\Resources\CompanyEvents\RelationManagers\SessionsRelationManager;
use App\Filament\Support\AudienceCriteriaSchema;
use App\Filament\Support\ServiceDeskActions;
use App\Filament\Support\TimezoneFields;
use App\Filament\Support\WorkforceBulkWizards;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * HROne parity GO-15 (HR Desk → Engage → Events, matrix 27 §27.8.3): the events register and the "Add event" wizard —
 * Basics (name, type and sub-type, description, agenda, host, mandatory, seats, nominations) · Schedule (start date and
 * time, duration HH:MM, time zone, repeat daily / weekly up to an end date) · Place & material (online / offline / hybrid,
 * location or venue, meeting URL, attachments, video link) · Audience & feedback (saved audience or criteria, pasted
 * employee codes, pre- / post-event feedback templates, publish now) · Review. The event's page lists the RSVPs and the
 * sessions, where attendance is marked per session and downloaded as CSV. For engagement.events.
 */
class CompanyEventResource extends Resource
{
    protected static ?string $model = CompanyEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Events';

    protected static ?string $modelLabel = 'event';

    protected static ?string $pluralModelLabel = 'events';

    protected static ?string $slug = 'events';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 3;

    public const TIMELINES = ['upcoming' => 'Upcoming', 'ongoing' => 'Ongoing', 'past' => 'Past'];

    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public static function canAccess(): bool
    {
        return CompanyEvents::runs(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        $frozen = fn (?CompanyEvent $record) => $record !== null && $record->status !== 'draft';
        $mandatory = fn (Get $get) => (bool) $get('is_mandatory');

        return $schema->components([
            Section::make('Basics')->description('What the event is, who hosts it and who may come.')->columns(2)->schema([
                TextInput::make('title')->label('Event name')->required()->maxLength(CompanyEvents::TITLE_MAX)->columnSpanFull(),
                Select::make('event_type')->label('Type of event')->native(false)->options(fn () => app(OptionLists::class)->options('event_types')),
                Select::make('event_sub_type')->label('Type of sub event')->native(false)->options(fn () => app(OptionLists::class)->options('event_sub_types')),
                Textarea::make('description')->rows(3)->maxLength(5000)->columnSpanFull(),
                MarkdownEditor::make('agenda')->label('Agenda')->maxLength(10000)->columnSpanFull(),
                WorkforceBulkWizards::employeeSelect('host_employee_id', 'Event host', true)->helperText('One host. The host may also mark attendance.'),
                Toggle::make('is_mandatory')->label('Is the event mandatory to attend?')->live()->disabled($frozen)
                    ->helperText('Invitees acknowledge a mandatory event; they cannot decline it. Seats and nominations do not apply.'),
                TextInput::make('seats')->label('Number of seats')->numeric()->minValue(1)->placeholder('Unlimited')->hidden($mandatory)
                    ->helperText('Acceptances stop when the seats are taken (no waiting list). Empty = unlimited.'),
                Toggle::make('allow_nomination')->label('Invitees may nominate colleagues')->hidden($mandatory),
            ]),
            Section::make('Schedule')->description(fn (?CompanyEvent $record) => $frozen($record) ? 'Published: the schedule is fixed (cancel and add a new event to change it).' : 'When it starts, for how long, and whether it repeats.')
                ->columns(3)->schema([
                    DatePicker::make('start_date')->label('Start date')->required()->disabled($frozen),
                    TimePicker::make('start_time')->label('Start time')->seconds(false)->required()->disabled($frozen),
                    TextInput::make('duration')->label('Duration (HH:MM)')->required()->default('01:00')->regex('/^\d{1,3}:[0-5]\d$/')->disabled($frozen),
                    ...array_map(fn ($field) => $field->disabled($frozen), TimezoneFields::make('timezone')),
                    Select::make('repeat')->label('Repeat')->options(CompanyEvents::REPEATS)->default('none')->native(false)->live()->disabled($frozen),
                    TextInput::make('repeat_every')->label(fn (Get $get) => $get('repeat') === 'weekly' ? 'Every (weeks)' : 'Every (days)')->numeric()->minValue(1)->maxValue(52)->default(1)
                        ->visible(fn (Get $get) => in_array($get('repeat'), ['daily', 'weekly'], true))->disabled($frozen),
                    DatePicker::make('repeat_until')->label('End date')->visible(fn (Get $get) => in_array($get('repeat'), ['daily', 'weekly'], true))
                        ->required(fn (Get $get) => in_array($get('repeat'), ['daily', 'weekly'], true))->disabled($frozen),
                    CheckboxList::make('repeat_weekdays')->label('On')->options(self::WEEKDAYS)->columns(7)->columnSpanFull()
                        ->visible(fn (Get $get) => $get('repeat') === 'weekly')->disabled($frozen),
                ]),
            Section::make('Place & material')->description('Where it is held, and what invitees can open beforehand.')->columns(2)->schema([
                Radio::make('mode')->label('Meeting location')->options(CompanyEvent::MODES)->default('offline')->required()->inline()->live()->columnSpanFull(),
                Select::make('location_id')->label('Location')->native(false)->searchable()->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (Get $get) => $get('mode') !== 'online'),
                TextInput::make('venue')->label('Venue / room')->maxLength(250)->visible(fn (Get $get) => $get('mode') !== 'online'),
                TextInput::make('meeting_url')->label('Meeting URL')->url()->maxLength(500)->visible(fn (Get $get) => $get('mode') !== 'offline')
                    ->required(fn (Get $get) => $get('mode') !== 'offline'),
                TextInput::make('video_url')->label('Video URL')->url()->maxLength(500),
                FileUpload::make('attachments')->label('Attachments')->multiple()->maxFiles(CompanyEvents::ATTACHMENTS_MAX)->disk(config('peopleos.documents.disk', 'local'))
                    ->directory(fn () => CompanyEvents::directory())->visibility('private')->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain'])
                    ->maxSize((int) config('peopleos.documents.max_kb', 10240))->columnSpanFull(),
            ]),
            Section::make('Audience & feedback')->description(fn (?CompanyEvent $record) => $frozen($record) ? 'Published: the audience is fixed; add invitees from the event\'s page.' : 'Who is invited when the event is published (only people in your scope), and the feedback asked of them.')
                ->columns(2)->schema([
                    Select::make('audience_id')->label('Saved audience')->placeholder('— the criteria below (empty = everyone employed in your scope) —')->native(false)
                        ->options(fn () => Audience::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())->columnSpanFull()->disabled($frozen),
                    Textarea::make('employee_codes')->label('Add bulk employees (employee codes)')->rows(2)->columnSpanFull()->disabled($frozen)
                        ->helperText('Paste codes separated by commas, spaces or new lines. They become "Named employees" of the criteria (every criterion you fill must match).'),
                    AudienceCriteriaSchema::section()->collapsed()->columnSpanFull()->disabled($frozen),
                    Select::make('pre_feedback_template_id')->label('Pre-event feedback')->native(false)->placeholder('None')
                        ->options(fn () => FeedbackTemplate::query()->active()->where('process', 'event_pre')->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText('Asked of each invitee who accepts (Feedback templates: "Pre-event feedback").'),
                    Select::make('post_feedback_template_id')->label('Post-event feedback')->native(false)->placeholder('None')
                        ->options(fn () => FeedbackTemplate::query()->active()->where('process', 'event_post')->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText('Asked of each attendee once marked attended.'),
                    Toggle::make('publish_now')->label('Publish when created (invitations go out now)')->default(true)->dehydrated(false)->visibleOn('create')->columnSpanFull(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $events = fn () => app(CompanyEvents::class);

        return $schema->components([
            Section::make('Event')->columns(4)->schema([
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => CompanyEvent::STATUSES[$state] ?? $state)->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('type')->label('Type of event')->state(fn (CompanyEvent $record) => $record->typeLabel())->placeholder('—'),
                TextEntry::make('sub_type')->label('Type of sub event')->state(fn (CompanyEvent $record) => $record->subTypeLabel())->placeholder('—'),
                TextEntry::make('host.person.full_name')->label('Host')->placeholder('—'),
                TextEntry::make('is_mandatory')->label('Mandatory')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
                TextEntry::make('seats')->label('Seats')->state(fn (CompanyEvent $record) => $record->is_mandatory ? 'Not limited (mandatory)' : ($record->seats ?? 'Unlimited')),
                TextEntry::make('allow_nomination')->label('Nominations')->formatStateUsing(fn ($state) => $state ? 'Invitees may nominate colleagues' : 'No'),
                TextEntry::make('creator.name')->label('Created by')->placeholder('—'),
                TextEntry::make('cancel_reason')->label('Cancelled because')->visible(fn (CompanyEvent $record) => $record->status === 'cancelled')->columnSpanFull(),
            ]),
            Section::make('RSVPs')->columns(5)->schema([
                TextEntry::make('rsvp_invited')->label('Invited')->state(fn (CompanyEvent $record) => $events()->stats($record)['invited']),
                TextEntry::make('rsvp_pending')->label('Not answered')->state(fn (CompanyEvent $record) => $events()->stats($record)['pending']),
                TextEntry::make('rsvp_accepted')->label(fn (CompanyEvent $record) => $record->is_mandatory ? 'Acknowledged' : 'Accepted (nominations)')->state(fn (CompanyEvent $record) => $events()->stats($record)['accepted']),
                TextEntry::make('rsvp_declined')->label('Declined')->state(fn (CompanyEvent $record) => $events()->stats($record)['declined']),
                TextEntry::make('rsvp_seats')->label('Seats left')->state(fn (CompanyEvent $record) => $events()->stats($record)['seats_left'] ?? '—'),
            ])->visible(fn (CompanyEvent $record) => $record->status !== 'draft'),
            Section::make('When & where')->columns(2)->schema([
                TextEntry::make('when')->label('Sessions')->state(fn (CompanyEvent $record) => $record->sessions->map(fn (CompanyEventSession $s) => $s->label($record->timezone))->take(10)->implode("\n").($record->sessions->count() > 10 ? "\n… and ".($record->sessions->count() - 10).' more' : ''))
                    ->extraAttributes(['class' => 'whitespace-pre-line'])->columnSpanFull(),
                TextEntry::make('mode')->label('Meeting location')->formatStateUsing(fn (string $state) => CompanyEvent::MODES[$state] ?? $state),
                TextEntry::make('place')->label('Location')->state(fn (CompanyEvent $record) => $record->placeLabel()),
                TextEntry::make('meeting_url')->label('Meeting URL')->url(fn (CompanyEvent $record) => $record->meeting_url, shouldOpenInNewTab: true)->placeholder('—'),
                TextEntry::make('video_url')->label('Video URL')->url(fn (CompanyEvent $record) => $record->video_url, shouldOpenInNewTab: true)->placeholder('—'),
                TextEntry::make('attachments')->label('Attachments')->columnSpanFull()->placeholder('—')
                    ->state(fn (CompanyEvent $record) => self::attachmentLinks($record)),
            ]),
            Section::make('Audience')->columns(2)->schema([
                TextEntry::make('audience_text')->label('Audience')->state(fn (CompanyEvent $record) => $record->audience_id !== null
                    ? 'Saved audience: '.($record->audience?->name ?? '#'.$record->audience_id) : AudienceCriteriaSchema::describe($record->audience_criteria)),
                TextEntry::make('invited_count')->label('Invitations')->placeholder('Sent when published'),
                TextEntry::make('feedback')->label('Feedback')->columnSpanFull()->state(fn (CompanyEvent $record) => collect([
                    $record->preFeedbackTemplate ? 'Before: '.$record->preFeedbackTemplate->name : null,
                    $record->postFeedbackTemplate ? 'After: '.$record->postFeedbackTemplate->name : null,
                ])->filter()->implode(' · ') ?: 'None'),
            ]),
            Section::make('Description and agenda')->schema([
                TextEntry::make('description')->placeholder('—'),
                TextEntry::make('agenda')->markdown()->placeholder('—'),
            ])->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $events = fn () => app(CompanyEvents::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['host.person', 'creator', 'location'])->withCount([
                'sessions',
                'invitations as accepted_count' => fn (Builder $q) => $q->withoutGlobalScope(AccessScope::class)->where('status', 'accepted'),
                'invitations as all_invitations_count' => fn (Builder $q) => $q->withoutGlobalScope(AccessScope::class),
            ]))
            ->columns([
                TextColumn::make('title')->label('Event')->searchable()->wrap()
                    ->description(fn (CompanyEvent $record) => collect([$record->typeLabel(), $record->subTypeLabel()])->filter()->implode(' · ') ?: null),
                TextColumn::make('starts_at')->label('Date')->sortable()
                    ->formatStateUsing(fn (CompanyEvent $record) => $record->starts_at?->copy()->setTimezone($record->timezone)->format('D j M Y'))
                    ->description(fn (CompanyEvent $record) => $record->starts_at?->copy()->setTimezone($record->timezone)->format('H:i').' '.$record->timezone.($record->sessions_count > 1 ? ' · '.$record->sessions_count.' sessions' : '')),
                TextColumn::make('mode')->label('Mode')->badge()->color('gray')->formatStateUsing(fn (string $state) => CompanyEvent::MODES[$state] ?? $state)->toggleable(),
                TextColumn::make('host.person.full_name')->label('Host')->placeholder('—')->toggleable(),
                TextColumn::make('seats')->label('Seats')->state(fn (CompanyEvent $record) => $record->is_mandatory ? 'Mandatory' : ($record->seats ?? 'Unlimited')),
                TextColumn::make('nomination')->label('Nomination')->state(fn (CompanyEvent $record) => $record->status === 'draft' ? '—' : $record->accepted_count.' of '.$record->all_invitations_count),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => CompanyEvent::STATUSES[$state] ?? $state)->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('creator.name')->label('Created by')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(CompanyEvent::STATUSES),
                SelectFilter::make('timeline')->label('Event timeline')->options(self::TIMELINES)
                    ->query(fn (Builder $query, array $data) => self::timeline($query, $data['value'] ?? null)),
                SelectFilter::make('event_type')->label('Type of event')->options(fn () => app(OptionLists::class)->labels('event_types')),
                SelectFilter::make('event_sub_type')->label('Type of sub event')->options(fn () => app(OptionLists::class)->labels('event_sub_types')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (CompanyEvent $record) => in_array($record->status, ['draft', 'published'], true)),
                ActionGroup::make([
                    self::publishAction(),
                    self::attendanceCsvAction(),
                    self::cancelAction(),
                ]),
            ]);
    }

    public static function publishAction(): Action
    {
        return Action::make('publish')->label('Publish')->icon(Heroicon::OutlinedPaperAirplane)->color('success')->requiresConfirmation()
            ->modalDescription('The audience is resolved in your scope now and everyone in it is invited and told. The schedule and the audience are fixed from then on.')
            ->visible(fn (CompanyEvent $record) => $record->status === 'draft' && CompanyEvents::runs(auth()->user()))
            ->action(fn (CompanyEvent $record) => ServiceDeskActions::run(fn () => app(CompanyEvents::class)->publish($record, auth()->user()), fn (CompanyEvent $e) => 'Published: '.$e->invited_count.' invited'));
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')->label('Unpublish (cancel)')->icon(Heroicon::OutlinedXMark)->color('danger')
            ->modalHeading(fn (CompanyEvent $record) => 'Cancel '.$record->title.'?')
            ->modalDescription(fn (CompanyEvent $record) => $record->status === 'draft' ? 'The draft is kept as cancelled; nobody was invited yet.'
                : (($record->starts_at !== null && $record->starts_at->lte(now())) ? 'This event has already started (back-dated). ' : '').'The event will be cancelled, and everyone invited who has not declined will be told. Invitations, answers and attendance are kept.')
            ->visible(fn (CompanyEvent $record) => in_array($record->status, ['draft', 'published'], true) && CompanyEvents::runs(auth()->user()))
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(CompanyEvents::REASON_MAX)])
            ->action(fn (CompanyEvent $record, array $data) => ServiceDeskActions::run(fn () => app(CompanyEvents::class)->cancel($record, (string) $data['reason'], auth()->user()), 'Event cancelled; invitees told'));
    }

    public static function completeAction(): Action
    {
        return Action::make('complete')->label('Mark completed')->icon(Heroicon::OutlinedCheckCircle)->color('gray')->requiresConfirmation()
            ->modalDescription('Closes the event for answers. Attendance can still be marked.')
            ->visible(fn (CompanyEvent $record) => $record->status === 'published' && CompanyEvents::runs(auth()->user()))
            ->action(fn (CompanyEvent $record) => ServiceDeskActions::run(fn () => app(CompanyEvents::class)->complete($record, auth()->user()), 'Event completed'));
    }

    /** "Download excel": the attendance sheet as CSV, every session or the one chosen ("Select date"). */
    public static function attendanceCsvAction(): Action
    {
        return Action::make('attendanceCsv')->label('Download attendance')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn (CompanyEvent $record) => $record->status !== 'draft' && CompanyEvents::runs(auth()->user()))
            ->schema([Select::make('session_id')->label('Select date')->placeholder('Every session')->native(false)
                ->options(fn (CompanyEvent $record) => $record->sessions()->get()->mapWithKeys(fn (CompanyEventSession $s) => [$s->id => $s->label($record->timezone)])->all())])
            ->action(fn (CompanyEvent $record, array $data) => self::download($record, filled($data['session_id'] ?? null) ? CompanyEventSession::query()->find((int) $data['session_id']) : null));
    }

    public static function download(CompanyEvent $event, ?CompanyEventSession $session = null): ?StreamedResponse
    {
        try {
            $csv = app(CompanyEvents::class)->attendanceCsv($event, auth()->user(), $session);
        } catch (\RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            return null;
        }

        return response()->streamDownload(fn () => print ($csv), 'event-'.$event->getKey().'-attendance'.($session ? '-session-'.$session->sequence : '').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Upcoming = first session ahead; ongoing = started, not ended; past = ended. */
    public static function timeline(Builder $query, ?string $value): Builder
    {
        $now = now();

        return match ($value) {
            'upcoming' => $query->where('starts_at', '>', $now),
            'ongoing' => $query->where('starts_at', '<=', $now)->where('ends_at', '>', $now),
            'past' => $query->where('ends_at', '<=', $now),
            default => $query,
        };
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'published' => 'success', 'completed' => 'info', 'cancelled' => 'danger', default => 'gray',
        };
    }

    public static function attachmentLinks(CompanyEvent $event): ?HtmlString
    {
        $paths = array_values((array) ($event->attachments ?? []));
        if ($paths === []) {
            return null;
        }
        $events = app(CompanyEvents::class);

        return new HtmlString(collect($paths)->map(fn (string $path, int $i) => '<a class="text-primary-600 underline" target="_blank" rel="noopener" href="'.e((string) $events->attachmentUrl($event, $i)).'">'.e(basename($path)).'</a>')->implode('<br>'));
    }

    public static function getRelations(): array
    {
        return [InvitationsRelationManager::class, SessionsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyEvents::route('/'),
            'create' => CreateCompanyEvent::route('/create'),
            'view' => ViewCompanyEvent::route('/{record}'),
            'edit' => EditCompanyEvent::route('/{record}/edit'),
        ];
    }

    /** @return array<string, mixed> the wizard's fields for an existing event (schedule from its first session and rule). */
    public static function formData(CompanyEvent $event, array $data): array
    {
        $first = $event->sessions()->first();
        $start = $first?->starts_at?->copy()->setTimezone($event->timezone);
        $rule = (array) ($event->recurrence ?? []);

        return [
            ...$data,
            'start_date' => $start?->toDateString(), 'start_time' => $start?->format('H:i'), 'duration' => CompanyEvents::hhmm((int) ($first?->duration_minutes ?? 60)),
            'repeat' => $rule['repeat'] ?? 'none', 'repeat_every' => $rule['every'] ?? 1, 'repeat_weekdays' => array_map('intval', (array) ($rule['weekdays'] ?? [])),
            'repeat_until' => $rule['until'] ?? null, 'audience_criteria' => (array) ($event->audience_criteria ?? []),
        ];
    }

    /** Invitee options for an attendance sheet (people not declined first), in the reader's scope. */
    public static function inviteeOptions(CompanyEvent $event): array
    {
        return CompanyEventInvitation::query()->where('event_id', $event->getKey())->with('employee.person')->orderByRaw("case when status = 'declined' then 1 else 0 end")->orderBy('id')->limit(1000)->get()
            ->mapWithKeys(fn (CompanyEventInvitation $i) => [$i->employee_id => trim(($i->employee?->employee_code ?? '').' · '.($i->employee?->person?->full_name ?? '')).($i->status === 'declined' ? ' (declined)' : '')])->all();
    }
}
