<?php

namespace App\Filament\Pages;

use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeavePrivacy;
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
 * HROne parity RQ-4: a private leave shows its type only to those LeavePrivacy lets see it in full ("Away" otherwise).
 */
class LeaveCalendar extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?string $title = 'Leave calendar';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.leave-calendar';

    public function getSubheading(): ?string
    {
        return 'Who is away when, across the teams you can see.';
    }

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
                // HROne parity RQ-4: a private leave reads "Away" for a viewer who may not see it in full (same badge colour).
                TextColumn::make('leaveType.name')->label('Type')->badge()->color('gray')
                    ->formatStateUsing(fn ($state, LeaveRequest $record) => app(LeavePrivacy::class)->showsDetails($record, auth()->user()) ? $state : LeavePrivacy::AWAY),
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
                // HROne parity RQ-4: filtering by type never finds a private leave the viewer may not see in full.
                SelectFilter::make('leave_type_id')->label('Type')->options(fn () => LeaveType::query()->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? app(LeavePrivacy::class)->restrictToDetails($query->where('leave_type_id', $data['value']), auth()->user())
                        : $query),
                SelectFilter::make('department')->relationship('employee.currentPosition.department', 'name')->preload(),
            ])
            ->defaultSort('from_date')
            ->emptyStateHeading('Nobody is away in this window');
    }
}
