<?php

namespace App\Filament\Resources\JvCodes;

use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Models\JvCode;
use App\Domain\Integration\Services\JvCodes;
use App\Filament\Resources\JvCodes\Pages\ManageJvCodes;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Data import DI-1 (Integration → JV mappings): the accounting (journal voucher) code of each record, per dimension and
 * optionally per accounting system. Many records may share a code. Kept through JvCodes (this screen and the uploads).
 */
class JvCodeResource extends Resource
{
    protected static ?string $model = JvCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'JV codes';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'JV code';

    protected static ?string $pluralModelLabel = 'JV codes';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Record')->columns(2)->schema([
                Select::make('dimension')->options(JvCodes::dimensions())->required()->disabledOn('edit'),
                TextInput::make('record_code')->label('PeopleOS code')->helperText('The record\'s code (the employee code for employees).')->required()->maxLength(64)
                    ->visibleOn('create'),
                Select::make('integration_system_id')->label('Accounting system')->placeholder('Every system')
                    ->options(fn () => IntegrationSystem::query()->orderBy('name')->pluck('name', 'id')->all())->disabledOn('edit'),
                TextInput::make('code')->label('JV code')->required()->maxLength(64)->helperText('Several records may share one code.'),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('system'))
            ->columns([
                TextColumn::make('dimension')->formatStateUsing(fn (string $state) => JvCodes::label($state))->badge()->color('gray')->sortable(),
                TextColumn::make('record_label')->label('Record')->state(fn (JvCode $record) => app(JvCodes::class)->recordLabel($record)),
                TextColumn::make('code')->label('JV code')->searchable()->sortable(),
                TextColumn::make('system.name')->label('Accounting system')->placeholder('Every system'),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('dimension')->options(JvCodes::dimensions()),
                SelectFilter::make('integration_system_id')->label('Accounting system')->relationship('system', 'name'),
            ])
            ->defaultSort('dimension')
            ->recordActions([
                EditAction::make()->using(function (JvCode $record, array $data) {
                    $reason = AuditReasonField::extract($data);
                    $target = app(JvCodes::class)->recordOf($record);

                    return $target === null ? $record : app(JvCodes::class)->set($record->dimension, $target, (string) $data['code'], $record->system, $reason);
                }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageJvCodes::route('/')];
    }
}
