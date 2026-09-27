<?php

namespace App\Filament\Resources\LegalEntities;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\LegalEntity;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\LegalEntities\Pages\ManageLegalEntities;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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

/** ADR-0001: the registered employer behind a company. Closed, never deleted. */
class LegalEntityResource extends Resource
{
    protected static ?string $model = LegalEntity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'legal_name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('company')->withCount('establishments');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('company_id')->label('Company')->required()->disabledOn('edit')
                ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('code')->required()->maxLength(32)->disabledOn('edit')->unique(ignoreRecord: true),
            TextInput::make('legal_name')->required()->maxLength(255),
            TextInput::make('trade_name')->maxLength(255),
            Select::make('legal_form')->options(config('peopleos.organisation.legal_forms')),
            TextInput::make('country')->required()->length(2)->default('IN')->helperText('ISO 3166-1 alpha-2'),
            TextInput::make('incorporation_identifier')->label('Incorporation identifier (e.g. CIN / LLPIN)')->maxLength(64),
            Select::make('status')->options(ActiveStatus::class)->default('active')->required(),
            DatePicker::make('effective_from')->required()->default(now()),
            DatePicker::make('effective_to')->afterOrEqual('effective_from'),
            Toggle::make('is_primary')->label('Primary legal entity of the company'),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('legal_name')->searchable()->sortable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('company.name')->label('Company')->sortable(),
                TextColumn::make('country'),
                TextColumn::make('establishments_count')->label('Establishments'),
                IconColumn::make('is_primary')->label('Primary')->boolean(),
                TextColumn::make('status')->badge(),
                TextColumn::make('effective_from')->date()->toggleable(),
                TextColumn::make('effective_to')->date()->placeholder('Open')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(ActiveStatus::class)])
            ->defaultSort('legal_name')
            ->recordActions([
                EditAction::make()->using(function (LegalEntity $record, array $data) {
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
        return ['index' => ManageLegalEntities::route('/')];
    }
}
