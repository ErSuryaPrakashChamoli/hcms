<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Leave\Models\LeaveRequest;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** MY TEAM (§54): today's headcount, who is present / on leave / absent, and the manager's Needs Attention. */
class MyTeam extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My Team';

    protected static ?string $title = 'My team';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.my-team';

    public static function canAccess(): bool
    {
        $me = ServiceDeskActions::me();

        return $me !== null && $me->directReports()->currentlyEffective()->exists();
    }

    public function reportIds()
    {
        return ServiceDeskActions::me()->directReports()->currentlyEffective()->pluck('employee_id');
    }

    /** @return array<string, int> */
    public function getSummary(): array
    {
        $ids = $this->reportIds();
        $today = now()->toDateString();
        $onLeave = LeaveRequest::query()->whereIn('employee_id', $ids)->where('status', 'approved')->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)->count();
        $records = AttendanceRecord::query()->whereIn('employee_id', $ids)->whereDate('date', $today)->get();

        return [
            'headcount' => $ids->count(),
            'present' => $records->whereIn('status', ['present', 'half_day', 'wfh', 'on_duty', 'incomplete'])->count(),
            'leave' => $onLeave,
            'absent' => $records->where('status', 'absent')->count(),
        ];
    }

    public function getNeedsAttention()
    {
        return app(NeedsAttention::class)->forManager(ServiceDeskActions::me(), auth()->user());
    }

    public function table(Table $table): Table
    {
        $today = now()->toDateString();

        return $table
            ->query(fn (): Builder => Employee::query()->with(['person', 'currentPosition.designation'])->whereIn('id', $this->reportIds()))
            ->columns([
                TextColumn::make('employee_code')->label('Code'),
                TextColumn::make('person.full_name')->label('Employee')->searchable(['first_name', 'last_name']),
                TextColumn::make('currentPosition.designation.name')->label('Designation')->placeholder('—'),
                TextColumn::make('lifecycle_state')->label('State')->badge()->color('gray'),
                TextColumn::make('today')->label('Today')->state(function (Employee $record) use ($today) {
                    if (LeaveRequest::query()->where('employee_id', $record->id)->where('status', 'approved')->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)->exists()) {
                        return 'On leave';
                    }
                    $status = AttendanceRecord::query()->where('employee_id', $record->id)->whereDate('date', $today)->value('status');

                    return $status ? config("peopleos.attendance.statuses.{$status}", $status) : 'No punch yet';
                })->badge()->color(fn ($state) => match (true) {
                    str_contains($state, 'Present'), str_contains($state, 'home') => 'success', $state === 'On leave' => 'info', $state === 'Absent' => 'danger', default => 'gray'
                }),
            ])
            ->recordActions([Action::make('open')->label('Open')->url(fn (Employee $record) => EmployeeResource::getUrl('view', ['record' => $record]))->visible(fn () => auth()->user()->can('employee.view'))]);
    }
}
