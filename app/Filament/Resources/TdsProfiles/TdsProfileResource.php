<?php

namespace App\Filament\Resources\TdsProfiles;

use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Models\TdsProfile;
use App\Domain\Organisation\Models\LegalEntity;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\TdsProfiles\Pages\ManageTdsProfiles;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Part L/T: the TDS deductor per legal entity (TAN, responsible person). */
class TdsProfileResource extends Resource
{
    protected static ?string $model = TdsProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'TDS deductor profiles';

    protected static ?int $navigationSort = 35;

    public static function form(Schema $schema): Schema
    {
        $registration = fn (string $type) => fn ($get) => StatutoryRegistration::query()->where('registration_type', $type)->where('legal_entity_id', $get('legal_entity_id'))->get()->mapWithKeys(fn ($r) => [$r->id => $r->typeLabel().' '.$r->maskedNumber()])->all();

        return $schema->columns(2)->components([
            Select::make('legal_entity_id')->label('Legal entity')->required()->live()->disabledOn('edit')->options(fn () => LegalEntity::query()->orderBy('legal_name')->pluck('legal_name', 'id')->all()),
            Select::make('deductor_category')->options(['company' => 'Company', 'government' => 'Government', 'others' => 'Others'])->default('company')->required(),
            Select::make('tan_registration_id')->label('TAN')->options($registration('tan')),
            Select::make('pan_registration_id')->label('Deductor PAN')->options($registration('pan')),
            TextInput::make('responsible_person_name')->required()->maxLength(255),
            TextInput::make('responsible_person_designation')->required()->maxLength(255),
            TextInput::make('responsible_person_pan')->label('Responsible person PAN')->maxLength(10)->password()->revealable(false)->dehydrated(fn ($state) => filled($state))->helperText('Stored encrypted; leave blank to keep.'),
            TextInput::make('email')->email(),
            TextInput::make('phone')->maxLength(32),
            Textarea::make('address')->rows(2)->columnSpanFull(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('legalEntity.legal_name')->label('Legal entity'),
                TextColumn::make('tan.registration_number_last4')->label('TAN')->formatStateUsing(fn (?string $state) => $state ? '••••'.$state : '—')->placeholder('Missing'),
                TextColumn::make('responsible_person_name')->label('Responsible person')->placeholder('Missing'),
                TextColumn::make('responsible_person_pan_last4')->label('PAN')->formatStateUsing(fn (?string $state) => $state ? '••••••'.$state : '—'),
            ])
            ->recordActions([
                EditAction::make()->using(function (TdsProfile $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ManageTdsProfiles::route('/')];
    }
}
