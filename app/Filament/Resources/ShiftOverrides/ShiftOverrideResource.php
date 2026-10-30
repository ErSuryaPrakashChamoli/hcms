<?php

namespace App\Filament\Resources\ShiftOverrides;

use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Attendance\Services\ShiftRoster;
use App\Filament\Resources\ShiftOverrides\Pages\ListShiftOverrides;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\TimeOfficeRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/** HROne parity HP-1: the day-level shift roster (HR rosters and approved shift changes) over the schedules. */
class ShiftOverrideResource extends Resource
{
    protected static ?string $model = ShiftOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Shift roster';

    protected static ?string $modelLabel = 'rostered day';

    protected static ?int $navigationSort = 25;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('attendance.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'shift', 'creator']))
            ->columns([
                // HROne parity TX-1: the WO-2 employee search (code, names, work e-mail, designation).
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (ShiftOverride $r) => $r->employee?->employee_code),
                TextColumn::make('date')->date('D, d M Y')->sortable(),
                TextColumn::make('shift.name')->label('Shift')->placeholder('Weekly off')->badge(),
                TextColumn::make('source')->badge()->formatStateUsing(fn (string $state) => ShiftOverride::SOURCES[$state] ?? $state)->color('gray'),
                TextColumn::make('reason')->limit(50)->toggleable(),
                TextColumn::make('creator.name')->label('By')->placeholder('—')->toggleable(),
                TextColumn::make('updated_at')->label('Changed on')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('shift_id')->label('Shift')->relationship('shift', 'name')->preload(),
                SelectFilter::make('source')->options(ShiftOverride::SOURCES),
                // HROne parity TX-1: rostered dates and the WO-2 employee filters.
                ...TimeOfficeRegisters::filters('attendance.view', ['effective_dates' => ['Rostered day', 'date']]),
            ])
            ->defaultSort('date', 'desc')
            ->recordActions([
                Action::make('remove')->label('Remove')->icon('heroicon-m-trash')->color('danger')
                    // HROne parity TX-2: attendance.roster (attendance.manage also grants it).
                    ->authorize(fn (ShiftOverride $record) => auth()->user()->can('roster', $record))
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])
                    ->action(function (ShiftOverride $record, array $data) {
                        try {
                            $record->loadMissing('employee');
                            app(ShiftRoster::class)->clear([$record->employee], $record->date, $record->date, $data['reason']);
                            Notification::make()->success()->title('Back to the schedule for that day')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListShiftOverrides::route('/')];
    }
}
