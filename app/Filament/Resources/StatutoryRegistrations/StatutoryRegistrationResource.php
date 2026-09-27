<?php

namespace App\Filament\Resources\StatutoryRegistrations;

use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Services\StatutoryRegistrations;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\StatutoryRegistrations\Pages\ManageStatutoryRegistrations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/** Phase 5 Part B/T: registrations with statutory authorities. Numbers are masked; changes need a reason; a new number supersedes. */
class StatutoryRegistrationResource extends Resource
{
    protected static ?string $model = StatutoryRegistration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Statutory registrations';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['legalEntity', 'establishment']);
    }

    public static function registrationFields(): array
    {
        return [
            Select::make('legal_entity_id')->label('Legal entity')->required()->live()
                ->options(fn () => LegalEntity::query()->orderBy('legal_name')->pluck('legal_name', 'id')->all()),
            Select::make('establishment_id')->label('Establishment')->placeholder('Entity level (PAN / TAN)')
                ->options(fn ($get) => Establishment::query()->where('legal_entity_id', $get('legal_entity_id'))->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('registration_type')->required()->options(collect(config('peopleos.compliance.registration_types'))->map(fn ($t) => $t['label'])->all()),
            TextInput::make('registration_number')->required()->maxLength(64)->helperText('Stored encrypted and shown masked.'),
            TextInput::make('registration_name')->maxLength(255),
            TextInput::make('jurisdiction')->label('Jurisdiction / office (e.g. regional office, AO)')->maxLength(128),
            Select::make('state_code')->label('State')->options(config('peopleos.compliance.states'))->helperText('Defaults to the establishment state.'),
            DatePicker::make('effective_from')->required(),
            Textarea::make('reason')->required()->rows(2)->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('registration_type')->label('Type')->formatStateUsing(fn (string $state) => config("peopleos.compliance.registration_types.{$state}.label", $state))->sortable(),
                TextColumn::make('registration_number_last4')->label('Number')->formatStateUsing(fn (?string $state) => '••••'.$state),
                TextColumn::make('statutory_authority')->label('Authority')->toggleable(),
                TextColumn::make('legalEntity.legal_name')->label('Legal entity'),
                TextColumn::make('establishment.name')->label('Establishment')->placeholder('Entity level'),
                TextColumn::make('state_code')->label('State')->placeholder('—'),
                TextColumn::make('effective_from')->date(),
                TextColumn::make('effective_to')->date()->placeholder('Open'),
                TextColumn::make('status')->badge(),
                TextColumn::make('verification_status')->label('Verification')->badge()->color(fn (string $state) => $state === 'verified' ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('registration_type')->options(collect(config('peopleos.compliance.registration_types'))->map(fn ($t) => $t['label'])->all()),
                SelectFilter::make('status')->options(config('peopleos.compliance.registration_statuses')),
            ])
            ->recordActions([
                Action::make('verify')->icon(Heroicon::OutlinedCheckBadge)->requiresConfirmation()
                    ->visible(fn (StatutoryRegistration $record) => $record->verification_status !== 'verified' && auth()->user()?->can('update', $record))
                    ->schema([
                        TextInput::make('source_reference')->label('Certificate / portal reference checked')->required()->maxLength(255),
                        Textarea::make('notes')->rows(2),
                    ])
                    ->action(fn (StatutoryRegistration $record, array $data) => self::attempt(fn () => app(StatutoryRegistrations::class)->verify($record, self::user(), $data['source_reference'], $data['notes'] ?? null), 'Registration verified')),
                Action::make('correct')->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (StatutoryRegistration $record) => auth()->user()?->can('update', $record))
                    ->fillForm(fn (StatutoryRegistration $record) => $record->only(['registration_name', 'jurisdiction', 'notes', 'effective_to']))
                    ->schema([
                        TextInput::make('registration_name')->maxLength(255),
                        TextInput::make('jurisdiction')->maxLength(128),
                        DatePicker::make('effective_to')->label('Closing date'),
                        Textarea::make('notes')->rows(2),
                        Textarea::make('reason')->required()->rows(2),
                    ])
                    ->action(function (StatutoryRegistration $record, array $data) {
                        $reason = $data['reason'];
                        unset($data['reason']);
                        self::attempt(fn () => app(StatutoryRegistrations::class)->update($record, $data, $reason), 'Registration updated');
                    }),
                Action::make('supersede')->label('New number')->icon(Heroicon::OutlinedArrowPath)->requiresConfirmation()
                    ->visible(fn (StatutoryRegistration $record) => $record->status === 'active' && auth()->user()?->can('update', $record))
                    ->schema([
                        TextInput::make('registration_number')->required()->maxLength(64),
                        DatePicker::make('effective_from')->required(),
                        Textarea::make('reason')->required()->rows(2),
                    ])
                    ->action(fn (StatutoryRegistration $record, array $data) => self::attempt(fn () => app(StatutoryRegistrations::class)->supersede($record, ['registration_number' => $data['registration_number']], $data['effective_from'], $data['reason'], self::user()), 'Registration superseded')),
            ]);
    }

    public static function attempt(callable $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->title($success)->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ManageStatutoryRegistrations::route('/')];
    }
}
