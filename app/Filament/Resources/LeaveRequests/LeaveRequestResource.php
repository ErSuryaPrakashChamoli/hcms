<?php

namespace App\Filament\Resources\LeaveRequests;

use App\Domain\Leave\Models\LeaveRequest;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Support\LeaveActions;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** The leave register (§25 reports). */
class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?string $navigationLabel = 'Leave requests';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->can('leave.approve')) {
            return null;
        }

        $count = LeaveRequest::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'leaveType', 'requester', 'reviewer']);

        if (! auth()->user()->can('leave.view')) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('user_id', auth()->id()));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name'])->description(fn (LeaveRequest $record) => $record->employee?->employee_code),
                TextColumn::make('leaveType.name')->label('Type')->badge()->color(fn (LeaveRequest $record) => $record->leaveType?->is_paid ? 'info' : 'danger'),
                TextColumn::make('from_date')->date('d M')->sortable(),
                TextColumn::make('to_date')->date('d M Y')->sortable(),
                TextColumn::make('days')->numeric(1)->sortable(),
                TextColumn::make('reason')->limit(50)->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                }),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Requested')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(LeaveRequest::STATUSES)->multiple(),
                SelectFilter::make('leave_type_id')->label('Type')->relationship('leaveType', 'name')->preload(),
                SelectFilter::make('department')->relationship('employee.currentPosition.department', 'name')->preload(),
                Filter::make('period')
                    ->schema([DatePicker::make('from')->native(false), DatePicker::make('until')->native(false)])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('to_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('from_date', '<=', $d))),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions(LeaveActions::forRequests());
    }

    public static function getPages(): array
    {
        return ['index' => ListLeaveRequests::route('/')];
    }
}
