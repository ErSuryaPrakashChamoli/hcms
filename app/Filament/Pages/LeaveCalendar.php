<?php

namespace App\Filament\Pages;

use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Team / organisation leave calendar (Phase 3 §42–§44): who is away when, inside the viewer's
 * scope (organisation + relationship scope through the query layer). Reasons are never shown here.
 */
class LeaveCalendar extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?string $title = 'Leave calendar';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.leave-calendar';

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('leave.view') ?? false) || (auth()->user()?->can('leave.approve') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => LeaveRequest::query()->with(['employee.person', 'employee.currentPosition.department', 'leaveType'])->whereIn('status', [...LeaveRequest::TAKEN, 'pending']))
            ->columns([
                TextColumn::make('from_date')->label('From')->date('D d M')->sortable(),
                TextColumn::make('to_date')->label('To')->date('D d M'),
                TextColumn::make('employee.person.display_name')->label('Employee'),
                TextColumn::make('employee.currentPosition.department.name')->label('Department')->placeholder('—'),
                TextColumn::make('leaveType.name')->label('Type')->badge()->color('gray'),
                TextColumn::make('days')->numeric(1),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'pending' => 'warning', default => 'gray'
                }),
            ])
            ->filters([
                Filter::make('window')->schema([DatePicker::make('from')->native(false)->default(now()->startOfMonth()), DatePicker::make('until')->native(false)->default(now()->addMonth()->endOfMonth())])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('to_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('from_date', '<=', $d))),
                SelectFilter::make('status')->options(['approved' => 'Approved', 'pending' => 'Pending', 'cancel_requested' => 'Cancellation requested']),
                SelectFilter::make('leave_type_id')->label('Type')->options(fn () => LeaveType::query()->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('department')->relationship('employee.currentPosition.department', 'name')->preload(),
            ])
            ->defaultSort('from_date')
            ->emptyStateHeading('Nobody is away in this window');
    }
}
