<?php

namespace Database\Seeders\Demo;

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Services\InvestmentDeclarations;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeCodeSeries;
use App\Domain\Engagement\Models\Survey;
use App\Domain\Engagement\Services\SurveyResponses;
use App\Domain\Engagement\Services\Surveys;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Grievance\Services\Grievances;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Services\Loans;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Team;
use App\Domain\People\Actions\UpdatePersonAction;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\FeedbackEntry;
use App\Domain\Performance\Services\Feedback;
use App\Domain\Projects\Models\Customer;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\Projects;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Models\Recognition;
use App\Domain\Recognition\Services\Recognitions;
use App\Domain\Skills\Models\EmployeeSkill;
use App\Domain\Skills\Services\SkillProfiles;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The demo organisation's recent history, so every module has something true to show on the day it is opened:
 * confirmations, birthdays, salary accounts, holidays for this year and next, approved past leave, attendance punches
 * from the first of last month to yesterday (late arrivals, half days, a missed punch, absences), expense reports,
 * recognition, a pulse survey with answers, loans, a grievance, a joiner in preboarding, skills, projects, a tax
 * declaration and continuous feedback.
 *
 * Everything goes through the domain services with a named actor; reasons say "Demo activity". Each section is
 * idempotent and guarded: a section that fails is reported and the others still run. Attendance moves with the calendar:
 * a later run adds the days since the last one (never a day that already has a punch).
 */
class DemoActivitySeeder extends Seeder
{
    public const REASON = 'Demo activity (synthetic)';

    private CarbonImmutable $today;

    private ?User $admin = null;

    public function run(TenantContext $tenants): void
    {
        $tenant = DemoOrganisation::tenant() ?? throw new RuntimeException('Run DemoTenantSeeder first: there is no demo organisation yet.');
        DemoOrganisation::assertClaimable($tenant);
        $this->today = CarbonImmutable::today();

        $tenants->runAs($tenant, function () {
            $this->admin = User::forCurrentTenant()->where('email', DemoOrganisation::ADMIN_EMAIL)->firstOrFail();
            auth()->setUser($this->admin);
            try {
                $this->section('Confirmations after probation', fn () => $this->confirmations());
                $this->section('Birthdays', fn () => $this->birthdays());
                $this->section('Salary bank accounts', fn () => $this->bankAccounts());
                $this->section('Holidays this year and next', fn () => $this->holidays());
                $this->section('Employee code series', fn () => $this->codeSeries());
                $this->section('Approved past leave', fn () => $this->pastLeave());
                $this->section('Attendance punches', fn () => $this->attendance(), atomic: false);
                $this->section('Expense reports', fn () => $this->expenses());
                $this->section('Recognition', fn () => $this->recognition());
                $this->section('Pulse survey', fn () => $this->survey());
                $this->section('Loans', fn () => $this->loans());
                $this->section('Grievance', fn () => $this->grievance());
                $this->section('Joiner in preboarding', fn () => $this->joiner());
                $this->section('Skills', fn () => $this->skills());
                $this->section('Projects', fn () => $this->projects());
                $this->section('Tax declaration', fn () => $this->taxDeclaration());
                $this->section('Continuous feedback', fn () => $this->feedback());
            } finally {
                auth()->forgetUser();
            }
        });
    }

    /**
     * Runs one part; a failure is reported (log and console) and the rest of the demo still gets built. An atomic part
     * leaves nothing half-made behind, so the next run builds it whole (attendance is not: each day stands on its own).
     */
    private function section(string $name, callable $part, bool $atomic = true): void
    {
        try {
            $atomic ? DB::transaction(fn () => $part()) : $part();
        } catch (Throwable $e) {
            report($e);
            $this->command?->warn("  Demo activity: {$name} skipped ({$e->getMessage()})");
        }
    }

    /** People past nine months of service are confirmed; the two with a probation review coming up stay on probation. */
    private function confirmations(): void
    {
        Employee::query()->with('person')->where('lifecycle_state', LifecycleState::Probation->value)->whereDate('joining_date', '<=', $this->today->subMonths(9))
            ->where(fn ($q) => $q->whereNull('probation_end_date')->orWhereDate('probation_end_date', '<', $this->today))
            ->orderBy('id')->each(fn (Employee $employee) => app(LifecycleEngine::class)->transition(
                $employee, LifecycleState::Confirmed, $employee->joining_date->copy()->addMonths(6), self::REASON.': confirmed after probation',
            ));
    }

    /** A date of birth for everyone without one: spread over the year, two of them this week. */
    private function birthdays(): void
    {
        $people = Employee::query()->with('person')->orderBy('id')->get()->filter(fn (Employee $e) => $e->person && $e->person->date_of_birth === null)->values();
        foreach ($people as $i => $employee) {
            $year = 1984 + ($employee->id * 7) % 16;
            $day = match ($i) {
                0 => $this->today->addDays(2),
                1 => $this->today->addDays(5),
                default => $this->today->startOfYear()->addDays(($employee->id * 37) % 365),
            };
            $birth = CarbonImmutable::create($year, $day->month, min($day->day, 28));
            app(UpdatePersonAction::class)->handle($employee->person, ['date_of_birth' => $birth->toDateString()], self::REASON.': date of birth');
        }
    }

    /** A fictional salary account for everyone employed without one (needed by the payroll bank file). */
    private function bankAccounts(): void
    {
        $banks = [['HDFC Bank', 'HDFC0001234', '50100', 14], ['ICICI Bank', 'ICIC0000456', '0012', 12], ['State Bank of India', 'SBIN0004567', '3', 11]];
        Employee::query()->employed()->with('person')->orderBy('id')->each(function (Employee $employee) use ($banks) {
            if (EmployeeBankAccount::query()->where('employee_id', $employee->id)->exists()) {
                return;
            }
            [$bank, $ifsc, $prefix, $length] = $banks[$employee->id % count($banks)];
            app(ChangeBankAccountAction::class)->add($employee, [
                'account_holder_name' => $employee->person->display_name, 'bank_name' => $bank, 'ifsc' => $ifsc, 'account_type' => 'salary', 'is_primary' => true,
                'account_number' => $prefix.str_pad((string) (1000 + $employee->id), $length - strlen($prefix), '7', STR_PAD_LEFT),
            ], $this->admin, self::REASON.': salary account');
        });
    }

    /** Fixed-date national holidays for this year and next in the calendar everyone follows (festival dates vary: left to HR). */
    private function holidays(): void
    {
        $calendar = HolidayCalendar::query()->where('code', 'NAT_2026')->first() ?? HolidayCalendar::query()->orderBy('id')->first();
        if ($calendar === null) {
            return;
        }
        foreach ([$this->today->year, $this->today->year + 1] as $year) {
            foreach (['01-26' => 'Republic Day', '05-01' => 'Labour Day', '08-15' => 'Independence Day', '10-02' => 'Gandhi Jayanti', '12-25' => 'Christmas'] as $date => $name) {
                if (Holiday::query()->where('holiday_calendar_id', $calendar->id)->whereDate('date', "{$year}-{$date}")->doesntExist()) {
                    Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => "{$year}-{$date}", 'name' => $name]);
                }
            }
        }
    }

    /** Company-wise employee codes for new hires (DT-0001, DS-0001); existing codes stay as they are. */
    private function codeSeries(): void
    {
        foreach (['DEMO-TECH' => 'DT-', 'DEMO-SVC' => 'DS-'] as $code => $prefix) {
            $company = Company::query()->where('code', $code)->first();
            if ($company && EmployeeCodeSeries::query()->where('company_id', $company->id)->doesntExist()) {
                EmployeeCodeSeries::create(['company_id' => $company->id, 'prefix' => $prefix, 'digits' => 4, 'allow_manual' => false]);
            }
        }
    }

    /** Leave taken in the last weeks, approved by the manager (or HR when the manager has no sign-in). */
    private function pastLeave(): void
    {
        foreach ([
            ['priya.nair@demo.local', 6, 6, 'Doctor\'s appointment'],
            ['karan.mehta@demo.local', 10, 9, 'Sister\'s wedding'],
            ['zoya.khan@demo.local', 13, 13, 'Fever'],
            ['nisha.reddy@demo.local', 17, 16, 'Family function in Hyderabad'],
            ['ishaan.gupta@demo.local', 3, 3, 'Personal work'],
        ] as [$email, $fromAgo, $toAgo, $why]) {
            $employee = $this->employee($email);
            if ($employee === null || LeaveRequest::query()->where('employee_id', $employee->id)->where('reason', 'like', '%'.self::REASON.'%')->exists()) {
                continue;
            }
            // $fromAgo >= $toAgo: the first day is the earlier one.
            $request = $this->requestLeave($employee, $this->today->subWeekdays($fromAgo)->toDateString(), $this->today->subWeekdays($toAgo)->toDateString(), "{$why} (".self::REASON.')');
            if ($request !== null && $request->status === 'pending') {
                app(Leaves::class)->approve($request, 'Approved. Take care.', $this->managerUser($employee) ?? $this->admin);
            }
        }
    }

    /**
     * Punches from the first of last month to yesterday for everyone employed: most days on time, some late, a few half
     * days, the odd missed out-punch and absence, decided by a hash of person and date so every run agrees. Days off,
     * holidays, leave and days that already have a punch are left alone; then every day in the range is processed.
     */
    private function attendance(): void
    {
        $from = $this->today->subMonthNoOverflow()->startOfMonth();
        $to = $this->today->subDay();
        $holidays = Holiday::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->pluck('date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->flip();
        $punches = app(PunchIngestion::class);
        $processor = app(AttendanceProcessor::class);

        Employee::query()->employed()->with('person')->whereDate('joining_date', '<=', $to)->orderBy('id')->each(function (Employee $employee) use ($from, $to, $holidays, $punches, $processor) {
            $start = CarbonImmutable::parse($employee->joining_date)->max($from);
            $leaveDays = LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
                ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $start)->get()
                ->flatMap(fn (LeaveRequest $r) => collect(CarbonPeriod::create($r->from_date, $r->to_date))->map->toDateString())->flip();
            $punched = AttendancePunch::query()->where('employee_id', $employee->id)->whereBetween('punched_at', [$start->startOfDay(), $to->endOfDay()])->pluck('punched_at')
                ->map(fn ($at) => CarbonImmutable::parse($at)->toDateString())->flip();
            $added = false;

            foreach (CarbonPeriod::create($start, $to) as $day) {
                $date = $day->toDateString();
                if ($day->isWeekend() || isset($holidays[$date]) || isset($leaveDays[$date]) || isset($punched[$date])) {
                    continue;
                }
                $roll = crc32($employee->id.'|'.$date) % 100;
                $jitter = crc32($date.'|'.$employee->id) % 25;
                $at = fn (int $minutes) => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
                [$in, $out] = match (true) {
                    $roll < 3 => [null, null],                                                // absent
                    $roll < 11 => [$at(575 + $jitter), $at(1120 + $jitter)],                  // late: 09:35–09:59 in
                    $roll < 14 => [$at(545 + $jitter), $at(810 + $jitter)],                   // half day: out by 13:30–13:54
                    $roll < 16 => [$at(545 + $jitter), null],                                 // missed out-punch
                    default => [$at(530 + $jitter), $at(1080 + $jitter * 3)],                 // 08:50–09:14 in, 18:00–19:12 out
                };
                foreach (array_filter(['in' => $in, 'out' => $out]) as $direction => $time) {
                    $punches->record($employee, "{$date} {$time}:00", $direction, 'import', note: self::REASON);
                    $added = true;
                }
            }
            if ($added) {
                $processor->processRange($employee, $start, $to);
            }
        });
    }

    /** Expense categories and policy, one report waiting for the manager and one approved. */
    private function expenses(): void
    {
        $category = function (string $code, string $name, string $group, array $extra = []): ExpenseCategory {
            return ExpenseCategory::query()->where('code', $code)->first() ?? app(ExpenseCategories::class)->save(null, [
                'type' => 'amount', 'name' => $name, 'code' => $code, 'expense_group_id' => ExpenseGroup::query()->where('code', $group)->value('id'),
            ] + $extra, self::REASON);
        };
        $food = $category('FOOD', 'Food and meals', 'MEALS', ['limit_basis' => 'amount', 'limit_exception_allowed' => true, 'limits' => [['amount' => 1500, 'period' => 'day']]]);
        $cab = $category('CAB', 'Cab and auto', 'CONVEYANCE');
        $hotel = $category('HOTEL', 'Hotel stay', 'STAY');
        if (Policy::query()->where('code', 'DEMO_EXPENSE')->doesntExist()) {
            $policy = Policy::create(['type' => 'expense', 'name' => 'Standard expense policy', 'code' => 'DEMO_EXPENSE']);
            app(Policies::class)->draft($policy, ['categories' => [['category' => $food->id], ['category' => $cab->id], ['category' => $hotel->id]]]);
            app(Policies::class)->publish($policy, '2025-04-01');
            PolicyAssignmentRule::create(['policy_type' => 'expense', 'policy_id' => $policy->id, 'name' => 'Everyone', 'conditions' => [], 'priority' => 1000]);
        }

        $reports = app(ExpenseReports::class);
        foreach ([
            ['priya.nair@demo.local', 'Client workshop in Gurugram', [['CAB', 2, 640], ['FOOD', 2, 820]], false],
            ['rahul.sharma@demo.local', 'Release night dinner and cab home', [['FOOD', 3, 1150], ['CAB', 3, 480]], true],
        ] as [$email, $title, $lines, $approve]) {
            $employee = $this->employee($email);
            if ($employee === null || $employee->user === null || ExpenseReport::query()->where('employee_id', $employee->id)->where('title', $title)->exists()) {
                continue;
            }
            $ids = collect($lines)->map(fn ($line) => app(ExpenseReceipts::class)->capture($employee, [
                'expense_category_id' => ExpenseCategory::query()->where('code', $line[0])->value('id'),
                'expense_date' => $this->today->subWeekdays($line[1])->toDateString(), 'amount' => $line[2], 'merchant' => $line[0] === 'CAB' ? 'Uber' : 'Social, Cyber Hub',
            ], $employee->user)->id)->all();
            $report = $reports->submit($reports->create($employee, $title, $ids, $employee->user), $employee->user);
            if ($approve && ($manager = $this->managerUser($employee))) {
                foreach ($report->parts()->get() as $part) {
                    $reports->approvePart($part, null, 'Approved', $manager);
                }
            }
        }
    }

    /** Badges given this month between colleagues (giving is limited to now: recognition is never backdated). */
    private function recognition(): void
    {
        foreach ([
            ['amit.verma@demo.local', 'priya.nair@demo.local', 'GREAT_JOB', 'Brought p95 latency down by a third before the release. Brilliant work!'],
            ['meera.iyer@demo.local', 'kavya.menon@demo.local', 'ABOVE_AND_BEYOND', 'Ran the whole appraisal calibration in a week. Thank you!'],
            ['priya.nair@demo.local', 'rahul.sharma@demo.local', 'TEAM_PLAYER', 'Picked up my on-call shift without being asked.'],
            ['neha.kapoor@demo.local', 'arjun.bose@demo.local', 'THANK_YOU', 'Payroll queries answered same day all month.'],
        ] as [$from, $to, $code, $message]) {
            $giver = User::forCurrentTenant()->where('email', $from)->first();
            $receiver = $this->employee($to);
            $badge = Badge::query()->where('code', $code)->first();
            if ($giver && $receiver && $badge && Recognition::query()->where('badge_id', $badge->id)->where('employee_id', $receiver->id)->where('giver_user_id', $giver->id)->doesntExist()) {
                app(Recognitions::class)->give($badge, $receiver, $giver, $message);
            }
        }
    }

    /** A pulse survey prepared by HR, approved by the administrator, open for two weeks, with a few answers. */
    private function survey(): void
    {
        $code = 'PULSE_'.$this->today->format('Y_m');
        $preparer = User::forCurrentTenant()->where('email', 'neha.kapoor@demo.local')->first();
        if ($preparer === null || Survey::query()->where('code', $code)->exists()) {
            return;
        }
        $surveys = app(Surveys::class);
        $survey = $surveys->create(['code' => $code, 'name' => $this->today->format('F').' pulse check', 'survey_type' => 'pulse', 'anonymity_mode' => 'anonymous',
            'closes_at' => $this->today->addDays(14)->setTime(18, 0)->toDateTimeString(),
            'audience_criteria' => ['lifecycle_states' => ['probation', 'confirmed', 'active', 'notice_period']]], $preparer);
        $version = $survey->versions()->first();
        foreach ([
            ['key' => 'mood', 'type' => 'likert', 'prompt' => 'I enjoy the work I do here', 'required' => true],
            ['key' => 'manager', 'type' => 'likert', 'prompt' => 'My manager gives me useful feedback', 'required' => true],
            ['key' => 'tools', 'type' => 'multiple_choice', 'prompt' => 'Which of these help you most?', 'options' => ['tools' => 'Laptop and tools', 'kb' => 'Knowledge base', 'rituals' => 'Team rituals', 'learning' => 'Learning budget']],
            ['key' => 'comment', 'type' => 'text', 'prompt' => 'One thing we should change'],
        ] as $question) {
            $surveys->saveQuestion($version, $question, $preparer);
        }
        $version = $surveys->publish($surveys->approve($surveys->submit($version->refresh(), $preparer), 'Looks good', $this->admin), $preparer)->refresh();
        foreach ([
            ['priya.nair@demo.local', '4', '5', ['tools', 'learning'], 'More pairing sessions across teams.'],
            ['rahul.sharma@demo.local', '5', '4', ['rituals'], 'Quieter focus rooms in Delhi.'],
            ['amit.verma@demo.local', '4', '4', ['kb', 'rituals'], 'Fewer status meetings.'],
        ] as [$email, $mood, $manager, $tools, $comment]) {
            $user = User::forCurrentTenant()->where('email', $email)->first();
            if ($user) {
                app(SurveyResponses::class)->submit($version, $user, ['mood' => $mood, 'manager' => $manager, 'tools' => $tools, 'comment' => $comment], 'demo-'.$code.'-'.$user->id);
            }
        }
    }

    /** A salary advance waiting for a decision and an approved personal loan repaid through payroll. */
    private function loans(): void
    {
        foreach ([
            ['rahul.sharma@demo.local', 'salary_advance', 30000, 3, 'Deposit for a new flat', false],
            ['priya.nair@demo.local', 'personal', 120000, 12, 'Home renovation', true],
        ] as [$email, $type, $principal, $installments, $why, $approve]) {
            $employee = $this->employee($email);
            if ($employee === null || Loan::query()->where('employee_id', $employee->id)->exists()) {
                continue;
            }
            $loan = app(Loans::class)->request($employee, $type, $principal, $installments, $why, $employee->user);
            if ($approve) {
                app(Loans::class)->approve($loan, 'Approved within policy', $this->admin);
            }
        }
    }

    private function grievance(): void
    {
        $subject = 'Noise in the Delhi open-plan area';
        $category = GrievanceCategory::query()->where('code', 'WORKPLACE')->first();
        $employee = $this->employee('rahul.sharma@demo.local');
        if ($category && $employee && Grievance::query()->where('subject', $subject)->doesntExist()) {
            app(Grievances::class)->raise($category, $employee, $subject, 'Calls from the sales bay make focused work very hard after 3 pm. Could we get a quiet zone or booths?', 'low', false, $employee->user);
        }
    }

    /** A software engineer joining in ten days: preboarding, with the standard onboarding plan started automatically. */
    private function joiner(): void
    {
        $email = 'sneha.joshi@demo.local';
        if (Employee::query()->where('work_email', $email)->exists()) {
            return;
        }
        $eng = Department::query()->where('code', 'ENG')->first();
        $se = Designation::query()->where('code', 'SE')->first();
        if ($eng === null || $se === null) {
            return;
        }
        app(HireEmployeeAction::class)->handle(
            ['first_name' => 'Sneha', 'last_name' => 'Joshi', 'personal_email' => 'sneha.joshi@example.test'],
            ['joining_date' => $this->today->addWeekdays(8)->toDateString(), 'work_email' => $email],
            ['company_id' => $eng->company_id, 'location_id' => Location::query()->where('code', 'DEL')->value('id'), 'department_id' => $eng->id,
                'designation_id' => $se->id, 'level_id' => $se->level_id, 'team_id' => Team::query()->where('code', 'PLAT')->value('id')],
            $this->employee('amit.verma@demo.local')?->id,
            self::REASON.': new joiner',
        );
    }

    /** A small skill library and the engineering and design teams' levels against their targets. */
    private function skills(): void
    {
        $library = ['LARAVEL' => ['Laravel', 'technical'], 'REACT' => ['React', 'technical'], 'SQL' => ['SQL and data modelling', 'technical'],
            'FIGMA' => ['Figma', 'technical'], 'COMMS' => ['Stakeholder communication', 'behavioural'], 'LEAD' => ['People leadership', 'leadership']];
        $skills = collect($library)->map(fn ($s, $code) => Skill::query()->firstOrCreate(['code' => $code], ['name' => $s[0], 'skill_type' => $s[1], 'status' => 'active']));
        foreach ([
            'priya.nair@demo.local' => ['LARAVEL' => [3, 4], 'SQL' => [3, 4], 'COMMS' => [2, 3]],
            'rahul.sharma@demo.local' => ['LARAVEL' => [2, 3], 'REACT' => [2, 3]],
            'amit.verma@demo.local' => ['LARAVEL' => [4, 4], 'LEAD' => [3, 4], 'COMMS' => [3, 4]],
            'rohan.pillai@demo.local' => ['REACT' => [2, 4], 'SQL' => [1, 3]],
            'sara.thomas@demo.local' => ['FIGMA' => [4, 4], 'LEAD' => [3, 4]],
            'ishaan.gupta@demo.local' => ['FIGMA' => [3, 4], 'COMMS' => [2, 3]],
        ] as $email => $levels) {
            $employee = $this->employee($email);
            if ($employee === null) {
                continue;
            }
            foreach ($levels as $code => [$level, $target]) {
                if (EmployeeSkill::query()->where('employee_id', $employee->id)->where('skill_id', $skills[$code]->id)->doesntExist()) {
                    app(SkillProfiles::class)->import($employee, $skills[$code]->id, $level, $target, $this->admin, self::REASON);
                }
            }
        }
    }

    /** A client and two projects with their teams. */
    private function projects(): void
    {
        $tech = Company::query()->where('code', 'DEMO-TECH')->first();
        if ($tech === null) {
            return;
        }
        $customer = Customer::query()->firstOrCreate(['code' => 'NORTHWIND'], ['name' => 'Northwind Retail (fictional)', 'contact_name' => 'Procurement desk', 'country_code' => 'IN', 'status' => 'active']);
        foreach ([
            ['PRJ-PLATFORM-V2', 'Platform v2', null, 'amit.verma@demo.local', false, ['priya.nair@demo.local' => ['Backend engineer', 60], 'rahul.sharma@demo.local' => ['Backend engineer', 80], 'leela.chandran@demo.local' => ['QA engineer', 50]]],
            ['PRJ-NW-APP', 'Northwind store app', $customer->id, 'ravi.kumar@demo.local', true, ['karan.mehta@demo.local' => ['Mobile engineer', 100], 'ananya.das@demo.local' => ['Mobile engineer', 100], 'zoya.khan@demo.local' => ['Designer', 40]]],
        ] as [$code, $name, $customerId, $managerEmail, $billable, $members]) {
            $project = Project::query()->where('code', $code)->first() ?? Project::create([
                'code' => $code, 'name' => $name, 'customer_id' => $customerId, 'company_id' => $tech->id, 'start_date' => $this->today->subMonths(3)->startOfMonth()->toDateString(),
                'end_date' => $this->today->addMonths(6)->endOfMonth()->toDateString(), 'manager_employee_id' => $this->employee($managerEmail)?->id, 'is_billable' => $billable, 'status' => 'active',
            ]);
            foreach ($members as $email => [$role, $allocation]) {
                $employee = $this->employee($email);
                if ($employee && $project->members()->where('employee_id', $employee->id)->doesntExist()) {
                    app(Projects::class)->addMember($project, $employee, ['role' => $role, 'allocation_percent' => $allocation, 'from_date' => max($project->start_date->toDateString(), $employee->joining_date->toDateString())], self::REASON);
                }
            }
        }
    }

    /** An investment declaration for this financial year, submitted for verification. */
    private function taxDeclaration(): void
    {
        $employee = $this->employee('priya.nair@demo.local');
        $start = $this->today->month >= 4 ? $this->today->year : $this->today->year - 1;
        $year = $start.'-'.substr((string) ($start + 1), -2);
        if ($employee === null || EmployeeTaxDeclaration::query()->where('employee_id', $employee->id)->where('financial_year', $year)->exists()) {
            return;
        }
        app(InvestmentDeclarations::class)->save($this->admin, $employee, $year, ['regime' => 'old', 'metro' => true, 'items' => [
            '80C.ppf' => ['amount' => 50000], '80C.elss' => ['amount' => 40000], '80D.self_family' => ['amount' => 18000], '80CCD1B.nps_tier1' => ['amount' => 50000],
        ]], true);
    }

    private function feedback(): void
    {
        foreach ([
            ['priya.nair@demo.local', 'amit.verma@demo.local', 'praise', 'public', 'Your write-up of the latency fix is now our team template. Thank you!'],
            ['rahul.sharma@demo.local', 'amit.verma@demo.local', 'constructive', 'manager', 'Great delivery this sprint. Next: raise risks earlier in stand-up.'],
            ['amit.verma@demo.local', 'priya.nair@demo.local', 'praise', 'manager', 'Always unblocks us quickly in reviews.'],
        ] as [$aboutEmail, $authorEmail, $type, $visibility, $message]) {
            $about = $this->employee($aboutEmail);
            $author = $this->employee($authorEmail);
            if ($about && $author && FeedbackEntry::query()->where('employee_id', $about->id)->where('author_id', $author->id)->doesntExist()) {
                app(Feedback::class)->give($about, $author, $type, $message, $visibility);
            }
        }
    }

    /** The sign-in of the employee's line manager, if the manager has one. */
    private function managerUser(Employee $employee): ?User
    {
        $managerId = $employee->currentManager()->value('manager_id');

        return $managerId ? Employee::query()->find($managerId)?->user : null;
    }

    private function employee(string $email): ?Employee
    {
        return Employee::query()->where('work_email', $email)->first();
    }

    /** Leave with the first type the employee may take on those days (probation and balances apply). */
    private function requestLeave(Employee $who, string $from, string $to, string $why): ?LeaveRequest
    {
        foreach (['CL', 'SL', 'EL'] as $code) {
            $type = LeaveType::query()->where('code', $code)->where('status', 'active')->first();
            if ($type === null) {
                continue;
            }
            try {
                return app(Leaves::class)->request($who, $type, $from, $to, $why, 'full', 'full', null, $who->user);
            } catch (Throwable) {
                // Not this type for this person on those days: try the next.
            }
        }

        return null;
    }
}
