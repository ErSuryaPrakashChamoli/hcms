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
            ]),
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
