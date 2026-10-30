<?php

namespace App\Filament\Resources\TravelRequests;

use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Services\TravelRequests;
use App\Filament\Resources\TravelRequests\Pages\ListTravelRequests;
use App\Filament\Resources\TravelRequests\Pages\ViewTravelRequest;
use App\Filament\Resources\TravelRequests\RelationManagers\LegsRelationManager;
use App\Filament\Resources\TravelRequests\RelationManagers\PartsRelationManager;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\ExpenseRegisters;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity EX-F: travel requests (trips) — the employee's own under Me (My travel requests), everyone's in
 * scope under Expense & travel (approvers, finance and the travel desk). Requested with their legs and the travel form,
 * approved or rejected (with their advance), booked by the travel desk (legs on the trip's page), cancelled — an approved
 * trip after approval. Owner decision 2026-10-29: a trip spanning several workflow groups is decided part by part
 * ("Approvals by workflow group" on its page, or the Approval Center), never as a whole.
 */
class TravelRequestResource extends Resource
{
    protected static ?string $model = TravelRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $modelLabel = 'travel request';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function oversees(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('expense.view') || $user->can('expense.approve') || $user->can('expense.pay') || $user->can('expense.travel_desk'));
    }

    public static function getNavigationGroup(): ?string
    {
        return static::oversees() ? 'Expense & travel' : 'Me';
    }

    public static function getNavigationLabel(): string
    {
        return static::oversees() ? 'Travel requests' : 'My travel requests';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'legs', 'parts', 'decider']);

        return static::oversees() ? $query : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function infolist(Schema $schema): Schema
    {
        $base = fn () => app(CurrencyRates::class)->baseCurrency();
        $masked = fn (?string $value) => $value === null ? '—' : '•••• '.mb_substr($value, -4);

        return $schema->components([
            Section::make('Trip')->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('employee.person.display_name')->label('Employee'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => TravelRequest::STATUSES[$state] ?? $state),
                TextEntry::make('trip_state')->label('Trip')->state(fn (TravelRequest $r) => match ($r->tripState()) {
                    'live' => 'Travelling now', 'upcoming' => 'Upcoming', 'completed' => 'Completed', default => '—',
                }),
                TextEntry::make('start_date')->label('Starts on')->date(),
                TextEntry::make('end_date')->label('Ends on')->date(),
                IconEntry::make('is_international')->label('International')->boolean(),
                TextEntry::make('estimated_cost')->label('Estimated cost')->formatStateUsing(fn (TravelRequest $r) => $r->estimated_cost !== null ? $r->currency.' '.number_format((float) $r->estimated_cost, 2) : '—')->placeholder('—'),
                TextEntry::make('estimated_cost_base')->label('Estimated cost (base)')->money($base)->placeholder('—'),
                TextEntry::make('advance.number')->label('Advance')->formatStateUsing(fn (TravelRequest $r) => $r->advance ? $r->advance->number.' · '.(ExpenseAdvance::STATUSES[$r->advance->status] ?? $r->advance->status) : '—')->placeholder('—'),
                TextEntry::make('visa_number')->label('Visa number')->formatStateUsing(fn (?string $state) => $masked($state))->placeholder('—')->visible(fn (TravelRequest $r) => (bool) $r->is_international),
                TextEntry::make('passport_number')->label('Passport number')->formatStateUsing(fn (?string $state) => $masked($state))->placeholder('—')->visible(fn (TravelRequest $r) => (bool) $r->is_international),
                TextEntry::make('purpose')->columnSpanFull(),
                TextEntry::make('backdated_reason')->label('Reason for the delay')->columnSpanFull()->visible(fn (TravelRequest $r) => filled($r->backdated_reason)),
                // Owner decision 2026-10-29: the approvals of a trip spanning several workflow groups.
                TextEntry::make('approvals')->label('Approvals')->columnSpanFull()->visible(fn (TravelRequest $r) => $r->hasParts())
                    ->state(fn (TravelRequest $r) => $r->parts->where('status', 'approved')->count().' of '.$r->parts->count().' workflow groups approved'
                        .(($rejected = $r->parts->firstWhere('status', 'rejected')) !== null ? ' · rejected by '.$rejected->label : '')),
                TextEntry::make('decision_note')->label('Decision note')->columnSpanFull()->placeholder('—'),
                TextEntry::make('cancel_reason')->label('Cancellation reason')->columnSpanFull()->visible(fn (TravelRequest $r) => filled($r->cancel_reason)),
                // HROne parity XO-1: a refused cancellation stays on record.
                TextEntry::make('cancel_refused_at')->label('Cancellation refused on')->dateTime()->visible(fn (TravelRequest $r) => $r->cancel_refused_at !== null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $run = fn (Closure $call, string $done) => function (TravelRequest $record, array $data = []) use ($call, $done) {
            try {
                $call($record, $data);
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };
        $trips = fn () => app(TravelRequests::class);
        $can = fn (string $ability, string ...$statuses) => fn (TravelRequest $r) => in_array($r->status, $statuses, true) && auth()->user()->can($ability, $r);
        // Owner decision 2026-10-29: a trip of several workflow groups is decided part by part on its page.
        $whole = fn (string $ability, string ...$statuses) => fn (TravelRequest $r) => ! $r->hasParts() && $can($ability, ...$statuses)($r);

        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                // HROne parity XO-1 (WO-2): the employee by code, names, work e-mail or designation.
                TextColumn::make('employee.person.display_name')->label('Employee')->visible(fn () => static::oversees())
                    ->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('purpose')->limit(40),
                TextColumn::make('start_date')->label('Starts on')->date(),
                TextColumn::make('end_date')->label('Ends on')->date(),
                TextColumn::make('destinations')->label('To')->state(fn (TravelRequest $r) => $r->destinations())->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => TravelRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'approved', 'booked' => 'success', 'booking' => 'info', 'rejected', 'cancelled' => 'gray', default => 'warning',
                    }),
                // HROne parity XO-1 (matrix 26 §26.2.1 #2, #3, #6, #7): requested / decided dates, decided by and whom a
                // waiting trip waits with — for the HR side, hidden until chosen (the preset views show them).
                TextColumn::make('submitted_at')->label('Requested on')->date()->sortable()->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('waiting_with')->label('Waiting with')->state(fn (TravelRequest $r) => ExpenseRegisters::tripWaitingWith($r))->placeholder('—')->wrap()
                    ->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('decided_at')->label('Decided on')->date()->sortable()->placeholder('—')->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('decider.name')->label('Decided by')->placeholder('—')->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancel_refused_at')->label('Cancellation refused on')->date()->placeholder('—')->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(TravelRequest::STATUSES)->multiple(),
                // HROne parity XO-1 (§26.2.1 #9, §26.2.4 #1): cancellations, requested / travel / decided dates and the WO-2
                // employee filters, for the HR side only.
                ExpenseRegisters::cancellation(fn () => static::oversees()),
                ExpenseRegisters::travelDates(fn () => static::oversees()),
                ...ExpenseRegisters::filters(fn () => static::oversees(), ['request_dates' => ['Requested', 'submitted_at'], 'decision_dates' => ['Decided', 'decided_at']]),
            ])
            ->defaultSort('start_date', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')->icon('heroicon-m-check')->color('success')->visible($whole('approve', 'submitted'))
                    ->schema([Textarea::make('note')->maxLength(500)])->action($run(fn ($r, $d) => $trips()->approve($r, $d['note'] ?? null), 'Travel approved')),
                Action::make('reject')->icon('heroicon-m-x-mark')->color('danger')->visible($whole('approve', 'submitted'))
                    ->schema([Textarea::make('note')->required()->maxLength(500)])->action($run(fn ($r, $d) => $trips()->reject($r, $d['note']), 'Travel rejected')),
                Action::make('approveCancellation')->label('Approve cancellation')->icon('heroicon-m-check')->color('success')->visible($can('approve', 'cancel_requested'))
                    ->schema([Textarea::make('note')->maxLength(500)])->action($run(fn ($r, $d) => $trips()->approveCancellation($r, $d['note'] ?? null), 'Travel cancelled')),
                Action::make('keepTrip')->label('Keep trip')->icon('heroicon-m-x-mark')->color('gray')->visible($can('approve', 'cancel_requested'))
                    ->schema([Textarea::make('note')->required()->maxLength(500)])->action($run(fn ($r, $d) => $trips()->rejectCancellation($r, $d['note']), 'Cancellation refused: the trip stays approved')),
                static::cancelAction(),
            ]);
    }

    /** Cancel: at once while waiting for approval (or by an approver), otherwise the cancellation waits for approval. */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')->label('Cancel trip')->icon('heroicon-m-arrow-uturn-left')->color('gray')
            ->visible(fn (TravelRequest $r) => in_array($r->status, ['submitted', 'approved', 'booking', 'booked'], true) && (auth()->user()->can('update', $r) || auth()->user()->can('approve', $r)))
            ->schema([Textarea::make('reason')->required()->maxLength(500)])
            ->action(function (TravelRequest $record, array $data) {
                try {
                    $after = app(TravelRequests::class)->cancel($record, $data['reason']);
                    Notification::make()->success()->title($after->status === 'cancelled' ? 'Travel request cancelled' : 'Cancellation sent for approval')->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [LegsRelationManager::class, PartsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListTravelRequests::route('/'), 'view' => ViewTravelRequest::route('/{record}')];
    }
}
