<?php

namespace App\Filament\Resources\WorkSchedules;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\WorkSchedules\Pages\CreateWorkSchedule;
use App\Filament\Resources\WorkSchedules\Pages\EditWorkSchedule;
use App\Filament\Resources\WorkSchedules\Pages\ListWorkSchedules;
use App\Filament\Resources\WorkSchedules\RelationManagers\RulesRelationManager;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Work schedules (§13, §14): weekly or rotating patterns of shifts and weekly offs. */
class WorkScheduleResource extends Resource
{
    protected static ?string $model = WorkSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $shiftOptions = fn () => Shift::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all();

        return $schema->components([
            Section::make('Schedule')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->required()->maxLength(32)->alphaDash(),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                DatePicker::make('effective_from')->native(false)->helperText('Rotation anchor when no individual assignment exists.'),
                DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
            ]),
            Section::make('Weekly pattern')
                ->description('One week is a plain schedule; add more weeks to rotate. Leave a day empty for a weekly off.')
                ->schema([
                    Repeater::make('pattern')
                        ->label('Weeks')
                        ->schema(array_map(fn (string $day) => Select::make($day)->label(ucfirst($day))->options($shiftOptions)->placeholder('Off'), WorkSchedule::DAYS))
                        ->columns(7)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->itemLabel(fn (array $state, $component) => 'Week')
                        ->columnSpanFull(),
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
                TextColumn::make('pattern')->label('Type')->state(fn (WorkSchedule $record) => $record->isRotational() ? count($record->pattern).'-week rotation' : 'Weekly'),
                TextColumn::make('working_days')->label('Days/week')->state(fn (WorkSchedule $record) => collect($record->pattern[0] ?? [])->filter()->count()),
                TextColumn::make('assignments_count')->counts('assignments')->label('Assigned'),
                TextColumn::make('rules_count')->counts('rules')->label('Rules'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RulesRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkSchedules::route('/'),
            'create' => CreateWorkSchedule::route('/create'),
            'edit' => EditWorkSchedule::route('/{record}/edit'),
        ];
    }
}
