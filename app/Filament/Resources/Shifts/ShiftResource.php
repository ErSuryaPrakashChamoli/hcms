<?php

namespace App\Filament\Resources\Shifts;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Shifts\Pages\CreateShift;
use App\Filament\Resources\Shifts\Pages\EditShift;
use App\Filament\Resources\Shifts\Pages\ListShifts;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Shift engine (§13): timings, grace, thresholds and overtime are configuration. */
class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Shift')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->required()->maxLength(32)->alphaDash(),
                ColorPicker::make('colour')->label('Shift colour'),
                Select::make('type')->options(['fixed' => 'Fixed timing', 'flexible' => 'Flexible (hours only)'])->default('fixed')->required()->live(),
                TimePicker::make('start_time')->seconds(false)->required(fn (Get $get) => $get('type') === 'fixed')->visible(fn (Get $get) => $get('type') === 'fixed'),
                TimePicker::make('end_time')->seconds(false)->required(fn (Get $get) => $get('type') === 'fixed')->visible(fn (Get $get) => $get('type') === 'fixed'),
                Toggle::make('crosses_midnight')->label('Ends next day')->visible(fn (Get $get) => $get('type') === 'fixed'),
                Select::make('timezone')->label('Timezone')->options(collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz]))->searchable()->placeholder('Location timezone, then application default')->helperText('Used only when the employee\'s work location has no timezone.'),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            ]),
            Section::make('Hours and thresholds')->columns(3)->schema([
                TextInput::make('full_day_minutes')->label('Full day (minutes)')->numeric()->default(480)->required(),
                TextInput::make('half_day_minutes')->label('Half day (minutes)')->numeric()->default(240)->required(),
                TextInput::make('break_minutes')->label('Break (minutes)')->numeric()->default(60),
                TextInput::make('grace_in_minutes')->label('Grace in (minutes)')->numeric()->default(15),
                TextInput::make('grace_out_minutes')->label('Grace out (minutes)')->numeric()->default(0),
                // HROne setup parity (TO-5): half lengths and how early before the start a check-in counts.
                TextInput::make('first_half_minutes')->label('First half lasts (minutes)')->numeric()->minValue(1)->placeholder('Half of the shift'),
                TextInput::make('early_checkin_minutes')->label('Check-in counts from (minutes before the start)')->numeric()->minValue(0)->maxValue(720)
                    ->placeholder('Organisation punch window'),
            ]),
            Section::make('Absence criteria')->columns(3)->description('Leave blank to keep the full-day and half-day minutes only.')->schema([
                TextInput::make('absent_first_half_after')->label('No check-in within N minutes of the start: first half absent')->numeric()->minValue(0),
                TextInput::make('absent_full_day_after')->label('No check-in within N minutes of the start: absent')->numeric()->minValue(0),
                TextInput::make('absent_second_half_before')->label('Check-out N minutes or more before the end: second half absent')->numeric()->minValue(0),
            ]),
            Section::make('Advanced')->columns(3)->schema([
                Toggle::make('ignore_before_start')->label('Do not count time before the shift starts'),
                TimePicker::make('cutoff_time')->label('Do not count time after')->seconds(false),
                Toggle::make('requestable')->label('Employees can request this shift')->default(true),
                Toggle::make('allow_short_leave')->label('Short leave allowed on this shift')->default(true),
                Toggle::make('allow_regularisation')->label('Regularisation allowed on this shift')->default(true),
                Toggle::make('allow_on_duty')->label('On duty allowed on this shift')->default(true),
                Toggle::make('allow_leave')->label('Leave allowed on this shift')->default(true),
                Toggle::make('allowance_eligible')->label('Days on this shift earn the shift allowance'),
            ])->collapsible(),
            Section::make('Breaks')->description('Unpaid breaks reduce worked minutes; paid breaks do not. When no breaks are configured the single "Break (minutes)" value is used.')->schema([
                Repeater::make('breaks')->relationship()->hiddenLabel()->columns(4)->defaultItems(0)->reorderableWithButtons()->orderColumn('sort_order')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(64)->placeholder('Lunch'),
                        TimePicker::make('starts_at')->seconds(false)->label('Starts'),
                        TextInput::make('duration_minutes')->numeric()->required()->minValue(1)->maxValue(720)->label('Minutes'),
                        Toggle::make('is_paid')->label('Paid')->inline(false),
                    ]),
            ])->collapsible(),
            Section::make('Overtime')->columns(2)->schema([
                Toggle::make('overtime_eligible')->label('Overtime eligible'),
                TextInput::make('min_overtime_minutes')->label('Minimum overtime (minutes)')->numeric()->default(30),
            ]),
            Section::make('Effective dates')->columns(2)->schema([
                DatePicker::make('effective_from')->native(false),
                DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('timing')->state(fn (Shift $record) => $record->timingLabel()),
                TextColumn::make('full_day_minutes')->label('Full day')->formatStateUsing(fn ($state) => round($state / 60, 1).' h'),
                TextColumn::make('grace_in_minutes')->label('Grace')->suffix(' min'),
                IconColumn::make('overtime_eligible')->label('OT')->boolean(),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShifts::route('/'),
            'create' => CreateShift::route('/create'),
            'edit' => EditShift::route('/{record}/edit'),
        ];
    }
}
