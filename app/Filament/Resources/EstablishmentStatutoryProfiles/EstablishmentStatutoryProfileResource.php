<?php

namespace App\Filament\Resources\EstablishmentStatutoryProfiles;

use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Organisation\Models\Establishment;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\EstablishmentStatutoryProfiles\Pages\ManageEstablishmentStatutoryProfiles;
use App\Filament\Resources\StatutoryRegistrations\StatutoryRegistrationResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Phase 5 Part D/T: statute applicability per establishment and period. No rates: those are verified rule versions. */
class EstablishmentStatutoryProfileResource extends Resource
{
    protected static ?string $model = EstablishmentStatutoryProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Statutory profiles';

    protected static ?string $modelLabel = 'establishment statutory profile';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['establishment', 'registration']);
    }

    public static function createFields(): array
    {
        return [
            Select::make('establishment_id')->label('Establishment')->required()->live()
                ->options(fn () => Establishment::query()->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('statute')->required()->live()->options(array_combine(EstablishmentStatutoryProfile::STATUTES, EstablishmentStatutoryProfile::STATUTES)),
            Toggle::make('applicable')->default(true),
            Select::make('statutory_registration_id')->label('Registration')
                ->options(fn ($get) => StatutoryRegistration::query()
                    ->where('legal_entity_id', Establishment::query()->whereKey($get('establishment_id'))->value('legal_entity_id'))
                    ->get()->mapWithKeys(fn ($r) => [$r->id => $r->typeLabel().' '.$r->maskedNumber()])->all()),
            Toggle::make('settings.restrict_to_ceiling')->label('Restrict PF to the statutory wage ceiling')->default(true)->visible(fn ($get) => $get('statute') === 'EPF'),
            DatePicker::make('effective_from')->required(),
            DatePicker::make('effective_to'),
            Textarea::make('reason')->required()->rows(2)->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('establishment.name')->label('Establishment')->sortable(),
                TextColumn::make('statute')->badge(),
                IconColumn::make('applicable')->boolean(),
                TextColumn::make('registration.registration_number_last4')->label('Registration')->formatStateUsing(fn (?string $state) => $state ? '••••'.$state : '—')->placeholder('—'),
                TextColumn::make('effective_from')->date(),
                TextColumn::make('effective_to')->date()->placeholder('Open'),
                TextColumn::make('reason')->limit(40)->toggleable(),
            ])
            ->filters([SelectFilter::make('statute')->options(array_combine(EstablishmentStatutoryProfile::STATUTES, EstablishmentStatutoryProfile::STATUTES))])
            ->recordActions([
                Action::make('close')->icon(Heroicon::OutlinedLockClosed)->requiresConfirmation()
                    ->visible(fn (EstablishmentStatutoryProfile $record) => $record->effective_to === null && auth()->user()?->can('update', $record))
                    ->schema([DatePicker::make('effective_to')->label('Last day')->required(), Textarea::make('reason')->required()->rows(2)])
                    ->action(fn (EstablishmentStatutoryProfile $record, array $data) => StatutoryRegistrationResource::attempt(
                        fn () => $record->withAuditReason($data['reason'])->update(['effective_to' => $data['effective_to']]),
                        'Profile closed',
                    )),
            ]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ManageEstablishmentStatutoryProfiles::route('/')];
    }
}
