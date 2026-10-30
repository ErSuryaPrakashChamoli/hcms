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
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
            // HROne parity HP-1: restricted holidays — optional holidays become a holiday only for those who choose them.
            Section::make('Restricted holidays')->columns(2)->schema([
                Toggle::make('optional_requires_selection')->label('Optional holidays must be chosen by each employee')
                    ->helperText('Off: optional holidays are holidays for everyone, as before.'),
                TextInput::make('restricted_holiday_quota')->label('Optional holidays an employee may choose per year')->numeric()->minValue(0)->maxValue(50)->placeholder('No limit'),
                // HROne setup parity (TO-6): proration for joiners, cancellation after processing, holidays without a shift.
                Repeater::make('rh_prorate_rules')->label('Quota for employees joining in the year')->columns(3)->defaultItems(0)->columnSpanFull()->addActionLabel('Add a joining-month range')
                    ->schema([
                        Select::make('from_month')->options(self::months())->required(),
                        Select::make('to_month')->options(self::months())->required(),
                        TextInput::make('quota')->numeric()->minValue(0)->maxValue(50)->required(),
                    ]),
                CheckboxList::make('rh_cancel_locked_for')->label('Cannot cancel a restricted holiday once attendance is processed')->options(['employee' => 'Employee', 'manager' => 'Manager', 'hr' => 'HR'])->columns(3),
                Toggle::make('rh_without_shift')->label('Allow restricted holidays on days without a shift')->default(true),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    /** @return array<int, string> */
    private static function months(): array
    {
        return collect(range(1, 12))->mapWithKeys(fn (int $m) => [$m => date('F', mktime(0, 0, 0, $m, 1))])->all();
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
