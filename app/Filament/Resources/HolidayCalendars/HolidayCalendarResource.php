<?php

namespace App\Filament\Resources\HolidayCalendars;

use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\HolidayCalendars\Pages\CreateHolidayCalendar;
use App\Filament\Resources\HolidayCalendars\Pages\EditHolidayCalendar;
use App\Filament\Resources\HolidayCalendars\Pages\ListHolidayCalendars;
use App\Filament\Resources\HolidayCalendars\RelationManagers\HolidaysRelationManager;
use App\Filament\Resources\HolidayCalendars\RelationManagers\RulesRelationManager;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Holiday engine (§15): several calendars, assigned by rules. */
class HolidayCalendarResource extends Resource
{
    protected static ?string $model = HolidayCalendar::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Calendar')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->required()->maxLength(32)->alphaDash(),
                TextInput::make('year')->numeric()->minValue(2000)->maxValue(2100)->placeholder('Any'),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('year')->placeholder('Any'),
                TextColumn::make('holidays_count')->counts('holidays')->label('Holidays'),
                TextColumn::make('rules_count')->counts('rules')->label('Rules'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [HolidaysRelationManager::class, RulesRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHolidayCalendars::route('/'),
            'create' => CreateHolidayCalendar::route('/create'),
            'edit' => EditHolidayCalendar::route('/{record}/edit'),
        ];
    }
}
