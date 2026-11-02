<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Models\CompanyEventInvitation;
use App\Domain\Engagement\Services\CompanyEvents;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Filament\Support\RecognitionActions;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use UnitEnum;

/**
 * HROne parity GO-15 (inbox `app-events` / `app-events-detail`, wall card "Upcoming Events"): the signed-in employee's
 * event invitations — name, type, date and time in the event's time zone, mode, place, host, their answer — with the
 * details (agenda, attachments, video and meeting links), "Attend" / "Decline" ("Acknowledge" for a mandatory event) and
 * "Nominate a colleague" when the event allows it. Own invitations only (no employee id in the URL).
 */
class MyEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My events';

    protected static ?string $title = 'My events';

    protected static ?string $slug = 'my-events';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.my-events';

    public static function canAccess(): bool
    {
        return self::me() !== null;
    }

    public static function me(): ?Employee
    {
        return auth()->check() ? app(CurrentEmployee::class)->of(auth()->user()) : null;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $me = self::me();
            $count = $me === null ? 0 : app(CompanyEvents::class)->pendingFor($me)->count();
        } catch (Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Events you are invited to. Answer before the event; a mandatory event is acknowledged.';
    }

    public function table(Table $table): Table
    {
        $events = fn () => app(CompanyEvents::class);
        $open = fn (CompanyEventInvitation $record) => $record->event?->status === 'published' && $record->event->ends_at?->gt(now());

        return $table
            ->query(fn (): Builder => $events()->invitationsQuery(self::me() ?? new Employee)->select('company_event_invitations.*')
                ->join('company_events', 'company_events.id', '=', 'company_event_invitations.event_id'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('company_events.starts_at')->orderByDesc('company_event_invitations.id'))
            ->columns([
                TextColumn::make('event.title')->label('Event')->wrap()
                    ->description(fn (CompanyEventInvitation $record) => collect([$record->event?->typeLabel(), $record->event?->subTypeLabel(), $record->event?->is_mandatory ? 'Mandatory' : null])->filter()->implode(' · ') ?: null),
                TextColumn::make('when')->label('Date and time')->state(fn (CompanyEventInvitation $record) => $events()->nextSession($record->event)?->label($record->event->timezone))
                    ->description(fn (CompanyEventInvitation $record) => $record->event->sessions->count() > 1 ? $record->event->sessions->count().' sessions' : null),
                TextColumn::make('place')->label('Where')->state(fn (CompanyEventInvitation $record) => $record->event->modeLabel().' · '.$record->event->placeLabel())->wrap(),
                TextColumn::make('event.host.person.full_name')->label('Host')->placeholder('—'),
                TextColumn::make('status')->label('Your answer')->badge()
                    ->state(fn (CompanyEventInvitation $record) => $record->event->status === 'cancelled' ? 'cancelled' : $record->status)
                    ->formatStateUsing(fn (string $state, CompanyEventInvitation $record) => match ($state) {
                        'cancelled' => 'Event cancelled', 'accepted' => $record->event->is_mandatory ? 'Acknowledged' : 'Attending',
                        'nominated' => 'Nominated by '.($record->nominator?->person?->display_name ?? 'a colleague'), default => CompanyEventInvitation::STATUSES[$state] ?? $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'accepted' => 'success', 'declined', 'cancelled' => 'danger', 'nominated' => 'warning', default => 'gray'
                    }),
            ])
            ->filters([
                SelectFilter::make('when')->label('Show')->options(['upcoming' => 'Upcoming', 'past' => 'Past and cancelled'])->default('upcoming')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'upcoming' => $query->where('company_events.status', 'published')->where('company_events.ends_at', '>', now()),
                        'past' => $query->where(fn (Builder $q) => $q->where('company_events.status', '!=', 'published')->orWhere('company_events.ends_at', '<=', now())),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('details')->label('Details')->icon(Heroicon::OutlinedEye)->color('gray')->slideOver()
                    ->modalHeading(fn (CompanyEventInvitation $record) => $record->event->title)
                    ->modalContent(fn (CompanyEventInvitation $record) => view('filament.pages.my-events-details', ['invitation' => $record, 'event' => $record->event, 'events' => $events()]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('accept')->label(fn (CompanyEventInvitation $record) => $record->event->is_mandatory ? 'Acknowledge' : 'Attend')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (CompanyEventInvitation $record) => $open($record) && $record->status !== 'accepted')
                    ->action(fn (CompanyEventInvitation $record) => ServiceDeskActions::run(fn () => $events()->respond($record, 'accepted', auth()->user()), fn (CompanyEventInvitation $i) => $i->event?->is_mandatory ? 'Acknowledged' : 'See you there')),
                Action::make('decline')->label('Decline')->icon(Heroicon::OutlinedXMark)->color('danger')->requiresConfirmation()
                    ->visible(fn (CompanyEventInvitation $record) => $open($record) && ! $record->event->is_mandatory && $record->status !== 'declined')
                    ->action(fn (CompanyEventInvitation $record) => ServiceDeskActions::run(fn () => $events()->respond($record, 'declined', auth()->user()), 'Declined')),
                Action::make('nominate')->label('Nominate a colleague')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                    ->visible(fn (CompanyEventInvitation $record) => $open($record) && $record->event->allow_nomination)
                    ->modalDescription('Your colleague is invited and told; they answer for themselves.')
                    ->schema([Select::make('employee_id')->label('Colleague')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => RecognitionActions::colleagues($search))
                        ->getOptionLabelUsing(fn ($value) => Employee::query()->with('person')->find($value)?->auditLabel())])
                    ->action(fn (CompanyEventInvitation $record, array $data) => ServiceDeskActions::run(fn () => $events()->nominate(CompanyEvent::query()->findOrFail($record->event_id),
                        Employee::query()->findOrFail((int) $data['employee_id']), auth()->user()), 'Colleague nominated')),
            ])
            ->emptyStateHeading('No event invitations')
            ->emptyStateDescription('When you are invited to an event, it shows here and in your tasks.');
    }
}
