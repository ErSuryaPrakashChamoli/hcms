<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Communication\Services\Communications;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveEntitlements;
use App\Domain\Leave\Services\LeaveYear;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Performance\Models\Goal;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Support\AttendanceActions;
use App\Filament\Support\ExitActions;
use App\Filament\Support\LeaveActions;
use App\Filament\Support\PendingRequestsPanel;
use App\Filament\Support\RequestChrome;
use App\Filament\Support\ServiceDeskActions;
use App\Filament\Support\WebPunch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use UnitEnum;

/**
 * MY PEOPLEOS · My Day (§52, §53): greeting, check in / out, today's status, balances, latest
 * payslip, Needs Attention, and the four quick actions. One screen, one decision, one next action.
 */
class MyDay extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My Day';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.my-day';

    public static function canAccess(): bool
    {
        return ServiceDeskActions::me() !== null;
    }

    public function getTitle(): string
    {
        $hour = (int) now()->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $first = $this->employee()->person?->first_name ?? auth()->user()->name;

        return "{$greeting}, {$first}";
    }

    public function employee(): Employee
    {
        return ServiceDeskActions::me()->loadMissing('person');
    }

    /** @return array<string, mixed> */
    public function getToday(): array
    {
        $employee = $this->employee();
        $punches = AttendancePunch::query()->where('employee_id', $employee->id)->where('processing_status', '!=', 'rejected')->whereDate('punched_at', now()->toDateString())->orderBy('punched_at')->get();
        $record = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', now()->toDateString())->first();
        $last = $punches->last();

        return [
            'checked_in' => $last !== null && $last->direction !== 'out',
            'first_in' => $punches->first()?->punched_at,
            'last_out' => $last?->direction === 'out' ? $last->punched_at : null,
            'punches' => $punches->count(),
            'needs_location' => WebPunch::needsLocation($employee),
            'status' => $record?->status,
            'status_label' => $record ? config("peopleos.attendance.statuses.{$record->status}", $record->status) : null,
        ];
    }

    /** @return array<int, array{code: string, name: string, available: float}> */
    public function getLeaveBalances(): array
    {
        $employee = $this->employee();
        $period = app(LeaveYear::class)->periodFor(now());

        return collect(app(LeaveEntitlements::class)->for($employee))->keys()
            ->map(fn (string $code) => LeaveType::query()->where('code', $code)->first())
            ->filter()
            ->map(fn (LeaveType $type) => ['code' => $type->code, 'name' => $type->name, 'available' => app(LeaveBalances::class)->balance($employee, $type, $period)->available()])
            ->values()->all();
    }

    public function getLatestPayslip(): ?Payslip
    {
        return Payslip::query()->where('employee_id', $this->employee()->id)->latest('generated_at')->first();
    }

    /** @return array<string, int> */
    public function getCounters(): array
    {
        $employee = $this->employee();

        return [
            'goals' => Goal::query()->where('employee_id', $employee->id)->where('status', 'active')->count(),
            'learning' => LearningEnrolment::query()->where('employee_id', $employee->id)->whereIn('status', LearningEnrolment::OPEN)->count(),
            'announcements' => app(Communications::class)->feedFor($employee)->count(),
        ];
    }

    public function getNeedsAttention()
    {
        return app(NeedsAttention::class)->forEmployee($this->employee(), auth()->user());
    }

    /** @param  array<string, mixed>  $client  what the browser sends (WebPunch) */
    public function punch(string $direction, array $client = []): void
    {
        if (! in_array($direction, ['in', 'out'], true)) {
            return;
        }
        try {
            WebPunch::record($this->employee(), $direction, $client);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Could not record')->body($e->getMessage())->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('applyLeave')->label('Apply leave')->icon(Heroicon::OutlinedCalendarDays)->color('primary')
                ->visible(fn () => auth()->user()->can('leave.apply'))
                ->schema(LeaveActions::applyForm(fn () => $this->employee()))
                ->action(fn (array $data) => LeaveActions::apply($this->employee(), $data)),
            Action::make('regularise')->label('Regularise attendance')->icon(Heroicon::OutlinedClock)->color('gray')
                ->visible(fn () => auth()->user()->can('attendance.regularise'))
                ->schema([
                    // HROne parity RQ-2: whose request it is.
                    RequestChrome::header(fn () => $this->employee()),
                    DatePicker::make('date')->native(false)->required()->default(now()->subDay())->maxDate(now()),
                    Select::make('type')->options(config('peopleos.attendance.regularisation_types'))->required(),
                    TimePicker::make('in')->label('Actual in')->seconds(false),
                    TimePicker::make('out')->label('Actual out')->seconds(false),
                    Textarea::make('reason')->required()->maxLength(255),
                    AttendanceActions::attachmentsField(),
                    // HROne parity RQ-2: who approves it (a read-only preview), who is informed, and the attendance policy.
                    RequestChrome::route('attendance_regularisation', fn () => $this->employee()),
                    // HROne parity RQ-1: the person's own open requests of the time-office family.
                    PendingRequestsPanel::make(fn () => $this->employee()),
                ])
                ->action(function (array $data) {
                    try {
                        $in = $data['in'] ? $data['date'].' '.$data['in'] : null;
                        $out = $data['out'] ? $data['date'].' '.$data['out'] : null;
                        $request = app(Regularisations::class)->request($this->employee(), $data['date'], $data['type'], $data['reason'], $in, $out, auth()->user(), array_values((array) ($data['attachments'] ?? [])));
                        RequestChrome::confirmed('attendance_regularisation', $request, 'Regularisation requested');
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Cannot request')->body($e->getMessage())->persistent()->send();
                    }
                }),
            Action::make('payslip')->label('View payslip')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->visible(fn () => $this->getLatestPayslip() !== null)
                ->url(fn () => PayslipResource::getUrl('view', ['record' => $this->getLatestPayslip()])),
            ServiceDeskActions::askHr(),
            ExitActions::resign(),
        ];
    }
}
