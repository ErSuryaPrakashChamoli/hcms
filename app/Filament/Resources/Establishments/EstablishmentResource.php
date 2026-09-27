<?php

namespace App\Filament\Resources\Establishments;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Establishments\Pages\ManageEstablishments;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** ADR-0001: a registered place of work. Its state selects PT and LWF rules. Closed, never deleted. */
class EstablishmentResource extends Resource
{
    protected static ?string $model = Establishment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['company', 'legalEntity']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('legal_entity_id')->label('Legal entity')->required()->disabledOn('edit')
                ->options(fn () => LegalEntity::query()->orderBy('legal_name')->pluck('legal_name', 'id')->all()),
            TextInput::make('code')->required()->maxLength(32)->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('establishment_type')->options(config('peopleos.organisation.establishment_types'))->default('office')->required(),
            Textarea::make('address')->rows(2)->columnSpanFull(),
            TextInput::make('country')->required()->length(2)->default('IN'),
            Select::make('state')->options(config('peopleos.compliance.states'))->searchable()->helperText('Selects the professional tax and labour welfare fund rules.'),
            TextInput::make('district')->maxLength(128),
            TextInput::make('postal_code')->maxLength(16),
            Select::make('status')->options(ActiveStatus::class)->default('active')->required(),
            DatePicker::make('effective_from')->required()->default(now()),
            DatePicker::make('effective_to')->afterOrEqual('effective_from'),
            Toggle::make('is_primary')->label('Principal establishment of the legal entity'),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('legalEntity.legal_name')->label('Legal entity'),
                TextColumn::make('company.name')->label('Company')->toggleable(),
                TextColumn::make('state')->placeholder('—'),
                TextColumn::make('establishment_type')->label('Type')->formatStateUsing(fn (?string $state) => config("peopleos.organisation.establishment_types.{$state}", $state)),
                IconColumn::make('is_primary')->label('Principal')->boolean(),
                TextColumn::make('status')->badge(),
                TextColumn::make('effective_to')->date()->placeholder('Open')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ActiveStatus::class),
                SelectFilter::make('state')->options(config('peopleos.compliance.states')),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->using(function (Establishment $record, array $data) {
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
        return ['index' => ManageEstablishments::route('/')];
    }
}
