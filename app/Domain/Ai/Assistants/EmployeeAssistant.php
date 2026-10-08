<?php

namespace App\Domain\Ai\Assistants;

use App\Domain\Ai\Services\AiAnswer;
use App\Domain\Assets\Models\AssetAssignment;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\HolidayResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveEntitlements;
use App\Domain\Leave\Services\LeaveYear;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Performance\Models\Goal;
use App\Domain\ServiceDesk\Models\Ticket;

/** Employee Assistant (§94): leave balance, attendance, payslip, requests, goals, learning, assets, holidays. */
final class EmployeeAssistant implements Assistant
{
    public function __construct(private readonly LeaveEntitlements $entitlements, private readonly LeaveBalances $balances, private readonly LeaveYear $years, private readonly HolidayResolver $holidays, private readonly NeedsAttention $attention, private readonly PolicyAssistant $policy) {}

    public function key(): string
    {
        return 'employee';
    }

    public function examples(): array
    {
        return ['How many leaves do I have?', 'Show my latest payslip', 'Was I late this month?', 'What is pending on my requests?', 'Upcoming holidays', 'What should I do next?'];
    }

    public function answer(User $user, ?Employee $employee, string $question): AiAnswer
    {
        if ($employee === null) {
            return AiAnswer::text('I can only answer personal questions for a user linked to an employee record.', 'no_employee');
        }

        $intent = Intents::detect($question, [
            'leave_balance' => ['leave balance', 'leaves do i have', 'how many leave', 'balance', 'leaves left', 'casual', 'earned', 'sick leave'],
            'leave_status' => ['leave request', 'leave status', 'my leave', 'approved', 'pending leave'],
            'payslip' => ['payslip', 'salary', 'pay slip', 'net pay', 'paid', 'ctc'],
            'attendance' => ['attendance', 'late', 'present', 'absent', 'check in', 'punch', 'worked', 'hours'],
            'requests' => ['ticket', 'request', 'hr request', 'ask hr', 'letter'],
            'holidays' => ['holiday', 'holidays', 'off day', 'public holiday'],
            'goals' => ['goal', 'okr', 'objective', 'target'],
            'learning' => ['course', 'training', 'learning', 'certificate'],
            'assets' => ['asset', 'laptop', 'equipment'],
            'attention' => ['what should i do', 'pending', 'next action', 'todo', 'to do', 'needs attention', 'next'],
            'policy' => ['policy', 'rule', 'how do i', 'how to', 'can i', 'allowed', 'process'],
        ]);

        return match ($intent) {
            'leave_balance' => $this->leaveBalance($employee),
            'leave_status' => $this->leaveStatus($employee),
            'payslip' => $this->payslip($employee),
            'attendance' => $this->attendance($employee),
            'requests' => $this->requests($employee),
            'holidays' => $this->holidays($employee),
            'goals' => $this->goals($employee),
            'learning' => $this->learning($employee),
            'assets' => $this->assets($employee),
            'attention' => $this->attention($employee, $user),
            'policy' => $this->policy->answer($user, $employee, $question),
            default => $this->fallback($user, $employee, $question),
        };
    }

    private function leaveBalance(Employee $employee): AiAnswer
    {
        $period = $this->years->periodFor(now());
        $lines = [];
        $facts = [];
        foreach (array_keys($this->entitlements->for($employee)) as $code) {
            $type = LeaveType::query()->where('code', $code)->first();
            if (! $type) {
                continue;
            }
            $balance = $this->balances->balance($employee, $type, $period);
            $lines[] = sprintf('%s: %.1f available', $type->name, $balance->available());
            $facts[$type->code] = $balance->available();
        }
        if ($lines === []) {
            return AiAnswer::text('No leave policy applies to you yet, so there are no balances to show. Ask HR if that looks wrong.', 'leave_balance', [], [['label' => 'Ask HR', 'url' => url('/admin/tickets')]]);
        }
        $answer = "Your leave balances for {$period}:\n- ".implode("\n- ", $lines);

        return new AiAnswer($answer, [['label' => 'Leave ledger', 'detail' => 'Balances derive from the leave ledger as of today']], [['label' => 'Apply leave', 'url' => url('/admin/my-day')], ['label' => 'Leave balances', 'url' => url('/admin/leave-balances')]], 'leave_balance', false, ['balances' => $facts]);
    }

    private function leaveStatus(Employee $employee): AiAnswer
    {
        $requests = LeaveRequest::query()->with('leaveType')->where('employee_id', $employee->id)->orderByDesc('from_date')->limit(5)->get();
        if ($requests->isEmpty()) {
            return AiAnswer::text('You have not applied for any leave yet.', 'leave_status', [], [['label' => 'Apply leave', 'url' => url('/admin/my-day')]]);
        }
        $lines = $requests->map(fn ($r) => sprintf('%s %s–%s (%.1f d): %s', $r->leaveType?->name, $r->from_date->toDateString(), $r->to_date->toDateString(), $r->days, config("peopleos.leave.statuses.{$r->status}", $r->status)))->all();

        return new AiAnswer("Your recent leave requests:\n- ".implode("\n- ", $lines), [['label' => 'Leave requests']], [['label' => 'Open leave requests', 'url' => url('/admin/leave-requests')]], 'leave_status', false, ['requests' => $lines]);
    }

    private function payslip(Employee $employee): AiAnswer
    {
        $payslip = Payslip::query()->where('employee_id', $employee->id)->latest('generated_at')->first();
        if ($payslip === null) {
            return AiAnswer::text('No payslip has been issued to you yet. Payslips appear here once payroll for a month is finalized.', 'payslip');
        }
        $t = $payslip->get('totals');
        $answer = sprintf('Your latest payslip is for %s: gross %s, deductions %s, net pay %s. Paid days %s, LOP %s.', $payslip->get('period.label'), number_format($t['gross'], 2), number_format($t['deductions'], 2), number_format($t['net'], 2), $payslip->get('days.paid'), $payslip->get('days.lop'));

        return new AiAnswer($answer, [['label' => 'Payslip '.$payslip->number]], [['label' => 'Open payslip', 'url' => url('/admin/payslips/'.$payslip->id)]], 'payslip', false, ['period' => $payslip->get('period.label'), 'totals' => $t]);
    }

    private function attendance(Employee $employee): AiAnswer
    {
        $records = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', '>=', now()->startOfMonth())->whereDate('date', '<=', now())->get();
        $today = $records->firstWhere(fn ($r) => $r->date->isToday());
        $present = $records->whereIn('status', ['present', 'half_day', 'wfh', 'on_duty'])->count();
        $late = $records->where('late_minutes', '>', 0)->count();
        $absent = $records->where('status', 'absent')->count();
        $answer = sprintf('This month so far: %d day(s) present, %d late arrival(s), %d absent. Today: %s.', $present, $late, $absent, $today ? config("peopleos.attendance.statuses.{$today->status}", $today->status) : 'not processed yet');
        $actions = [['label' => 'My Day (check in / regularise)', 'url' => url('/admin/my-day')]];
        if ($late > 0 || $absent > 0) {
            $actions[] = ['label' => 'Regularise attendance', 'url' => url('/admin/my-day')];
        }

        return new AiAnswer($answer, [['label' => 'Attendance records '.now()->format('M Y')]], $actions, 'attendance', false, compact('present', 'late', 'absent'));
    }

    private function requests(Employee $employee): AiAnswer
    {
        // Phase 12: only the employee's own visible requests (never a confidential case raised about them).
        $open = Ticket::query()->with(['category', 'service'])->where('employee_id', $employee->id)->where('visible_to_employee', true)->whereIn('status', Ticket::OPEN)->get();
        if ($open->isEmpty()) {
            return AiAnswer::text('You have no open HR requests. I can raise one for you from My Day → Ask HR.', 'requests', [], [['label' => 'Ask HR', 'url' => url('/admin/my-day')]]);
        }
        $lines = $open->map(fn ($t) => "{$t->number} {$t->serviceName()} — ".config("peopleos.servicedesk.statuses.{$t->status}"))->all();

        return new AiAnswer("Your open requests:\n- ".implode("\n- ", $lines), [['label' => 'Service desk']], [['label' => 'My requests', 'url' => url('/admin/tickets')]], 'requests', false, ['open' => $lines]);
    }

    private function holidays(Employee $employee): AiAnswer
    {
        $calendar = $this->holidays->calendarFor($employee, now());
        $upcoming = $calendar ? $calendar->holidays()->whereDate('date', '>=', now())->orderBy('date')->limit(5)->get() : collect();
        if ($upcoming->isEmpty()) {
            return AiAnswer::text('No upcoming holidays are published for your calendar.', 'holidays');
        }
        $lines = $upcoming->map(fn ($h) => $h->date->format('D d M').': '.$h->name)->all();

        return new AiAnswer("Upcoming holidays:\n- ".implode("\n- ", $lines), [['label' => 'Holiday calendar '.$calendar->name]], [], 'holidays', false, ['holidays' => $lines]);
    }

    private function goals(Employee $employee): AiAnswer
    {
        $goals = Goal::query()->where('employee_id', $employee->id)->where('status', 'active')->orderByDesc('weight')->get();
        if ($goals->isEmpty()) {
            return AiAnswer::text('You have no active goals. Add one from Performance → Goals.', 'goals', [], [['label' => 'Goals', 'url' => url('/admin/goals')]]);
        }
        $lines = $goals->map(fn ($g) => sprintf('%s — %.0f%% (weight %d%%)%s', $g->title, $g->progress, $g->weight, $g->due_date ? ', due '.$g->due_date->toDateString() : ''))->all();

        return new AiAnswer("Your active goals:\n- ".implode("\n- ", $lines), [['label' => 'Goals']], [['label' => 'Check in on goals', 'url' => url('/admin/goals')]], 'goals', false, ['goals' => $lines]);
    }

    private function learning(Employee $employee): AiAnswer
    {
        $open = LearningEnrolment::query()->with('course')->where('employee_id', $employee->id)->whereIn('status', LearningEnrolment::OPEN)->orderBy('due_on')->get();
        if ($open->isEmpty()) {
            return AiAnswer::text('You are up to date on learning: nothing is assigned or due.', 'learning', [], [['label' => 'Browse learning', 'url' => url('/admin/learning-enrolments')]]);
        }
        $lines = $open->map(fn ($e) => $e->course->title.' — '.$e->progress.'%'.($e->due_on ? ', due '.$e->due_on->toDateString() : '').($e->status === 'overdue' ? ' (overdue)' : ''))->all();

        return new AiAnswer("Learning on your plate:\n- ".implode("\n- ", $lines), [['label' => 'Enrolments']], [['label' => 'My learning', 'url' => url('/admin/learning-enrolments')]], 'learning', false, ['open' => $lines]);
    }

    private function assets(Employee $employee): AiAnswer
    {
        $assets = AssetAssignment::query()->with('asset')->where('employee_id', $employee->id)->where('status', 'active')->get();
        if ($assets->isEmpty()) {
            return AiAnswer::text('No company assets are recorded in your custody.', 'assets');
        }
        $lines = $assets->map(fn ($a) => "{$a->asset->name} [{$a->asset->asset_tag}]".($a->acknowledged_at ? '' : ' — receipt not yet acknowledged'))->all();

        return new AiAnswer("Assets in your custody:\n- ".implode("\n- ", $lines), [['label' => 'Asset assignments']], [['label' => 'My assets', 'url' => url('/admin/assets')]], 'assets', false, ['assets' => $lines]);
    }

    private function attention(Employee $employee, User $user): AiAnswer
    {
        $items = $this->attention->forEmployee($employee, $user);
        if ($items->isEmpty()) {
            return AiAnswer::text("You're all caught up — nothing needs your attention right now.", 'attention');
        }
        $lines = $items->map(fn ($i) => "{$i['title']} ({$i['count']}) — {$i['detail']}")->all();
        $actions = $items->filter(fn ($i) => $i['url'])->take(4)->map(fn ($i) => ['label' => $i['title'], 'url' => $i['url']])->values()->all();

        return new AiAnswer("Here is what needs your attention, most urgent first:\n- ".implode("\n- ", $lines), [['label' => 'Needs Attention']], $actions, 'attention', false, ['items' => $lines]);
    }

    private function fallback(User $user, Employee $employee, string $question): AiAnswer
    {
        $kb = $this->policy->answer($user, $employee, $question);
        if ($kb->intent === 'kb_match') {
            return $kb;
        }

        return AiAnswer::text('I can help with your leave balance, leave requests, attendance, payslips, HR requests, holidays, goals, learning, assets and what needs your attention. Try: "'.$this->examples()[0].'"', 'help', [], [['label' => 'Ask HR', 'url' => url('/admin/tickets')]]);
    }
}
