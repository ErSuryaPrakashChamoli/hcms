<?php

namespace App\Filament\Resources\TravelRequests\RelationManagers;

use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Models\TravelRequestLeg;
use App\Domain\Expense\Services\TravelBookings;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne setup parity EX-F: the trip's journeys and stays, who books each, and the booking: the travel desk records
 * company bookings (reference, provider, cost) here; the employee records their own.
 */
class LegsRelationManager extends RelationManager
{
    protected static string $relationship = 'legs';

    protected static ?string $title = 'Journeys and stays';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'bookingTicket']))
            ->columns([
                TextColumn::make('sequence')->label('#'),
                TextColumn::make('summary')->label('Leg')->state(fn (TravelRequestLeg $l) => $l->summary()),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('starts_on')->label('Date / check-in')->date(),
                TextColumn::make('ends_on')->label('Return / check-out')->date()->placeholder('—'),
                TextColumn::make('booked_by')->label('Booked by')->formatStateUsing(fn (string $state) => $state === 'company' ? 'Company' : 'Employee'),
                TextColumn::make('status')->label('Booking')->badge()->formatStateUsing(fn (string $state) => TravelRequestLeg::STATUSES[$state] ?? $state),
                TextColumn::make('reference')->label('Reference')->state(fn (TravelRequestLeg $l) => $l->booking_details['reference'] ?? null)->placeholder('—'),
                TextColumn::make('booking_cost_base')->label('Cost')->numeric(2)->placeholder('—'),
                TextColumn::make('bookingTicket.number')->label('Helpdesk request')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('recordBooking')->label('Record booking')->icon('heroicon-m-ticket')
                    ->visible(fn (TravelRequestLeg $record) => in_array($record->status, ['pending', 'booking', 'booked'], true) && $this->getOwnerRecord()->isApproved()
                        && app(TravelBookings::class)->canRecord(auth()->user(), $record))
                    ->fillForm(fn (TravelRequestLeg $record) => (array) ($record->booking_details ?? []) + ['currency' => app(CurrencyRates::class)->baseCurrency()])
                    ->schema([
                        TextInput::make('reference')->label('Booking reference (PNR / confirmation)')->required()->maxLength(64),
                        TextInput::make('provider')->label('Airline, railway, hotel or agent')->maxLength(120),
                        TextInput::make('cost')->numeric()->minValue(0),
                        TextInput::make('currency')->maxLength(3),
                        Textarea::make('notes')->maxLength(500)->rows(2),
                    ])
                    ->action(function (TravelRequestLeg $record, array $data) {
                        try {
                            app(TravelBookings::class)->record($record, $data);
                            Notification::make()->success()->title('Booking recorded')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
