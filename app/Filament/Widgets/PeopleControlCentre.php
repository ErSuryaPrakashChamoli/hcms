<?php

namespace App\Filament\Widgets;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Support\Tenancy\TenantContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** "Needs attention" for HR (§55, §57). */
class PeopleControlCentre extends StatsOverviewWidget
{
    protected ?string $heading = 'People Control Centre · needs attention';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return app(TenantContext::class)->has() && (auth()->user()?->can('employee.view') ?? false);
    }

    protected function getStats(): array
    {
        $today = now()->startOfDay();

        return [
            Stat::make('Joining this week', Employee::query()->whereIn('lifecycle_state', [LifecycleState::PreEmployee, LifecycleState::Preboarding])->whereBetween('expected_joining_date', [$today, $today->copy()->addDays(7)])->count())
                ->description('Pre-employees due to join'),
            Stat::make('Probation ending', Employee::query()->where('lifecycle_state', LifecycleState::Probation)->whereBetween('probation_end_date', [$today, $today->copy()->addDays(14)])->count())
                ->description('Within 14 days'),
            Stat::make('Probation overdue', Employee::query()->where('lifecycle_state', LifecycleState::Probation)->where('probation_end_date', '<', $today)->count())
                ->description('Decision pending')
                ->color('danger'),
            Stat::make('Onboarding overdue', OnboardingTask::query()->where('status', 'pending')->whereDate('due_on', '<', $today)->count())
                ->description('Tasks past due'),
            Stat::make('Open verifications', BgvCase::query()->whereIn('status', ['initiated', 'in_progress'])->count()),
            Stat::make('Documents to verify', EmployeeDocument::query()->where('status', 'pending')->count()),
            Stat::make('Approvals pending', WorkflowTask::query()->where('status', TaskStatus::Pending)->count()),
            Stat::make('Attendance exceptions', AttendanceRecord::query()->whereNotNull('exceptions')->where('is_locked', false)->whereDate('date', '>=', $today->copy()->subDays(7))->count())
                ->description('Last 7 days'),
            Stat::make('Regularisations pending', AttendanceRegularisation::query()->where('status', 'pending')->count()),
            Stat::make('On leave today', LeaveRequest::query()->where('status', 'approved')->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)->count()),
            Stat::make('Leave requests pending', LeaveRequest::query()->where('status', 'pending')->count()),
            Stat::make('Open service desk tickets', Ticket::query()->whereIn('status', Ticket::OPEN)->count())
                ->description(Ticket::query()->whereIn('status', Ticket::OPEN)->where('due_at', '<', now())->count().' past SLA')
                ->color('info'),
            Stat::make('Exits in progress', ExitCase::query()->whereIn('status', ExitCase::OPEN)->count())
                ->description(ExitCase::query()->whereIn('status', ExitCase::OPEN)->whereDate('last_working_day', '<=', now()->addDays(7))->count().' leaving this week')
                ->color('warning'),
            Stat::make('Open grievances', Grievance::query()->whereIn('status', Grievance::OPEN)->count())->color('danger'),
        ];
    }
}
