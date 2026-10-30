<?php

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Forms;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Experience\BulkOperations\CancelClaimOperation;
use App\Domain\Experience\BulkOperations\CancelLeaveOperation;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Experience\Services\CommandSearch;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\MyRequests as MyRequestsModel;
use App\Domain\Experience\Services\QuickActions;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Experience\Services\WorkInbox;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Loans\Models\Loan;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\Home;
use App\Filament\Pages\MyRequests;
use App\Filament\Pages\NewRequest;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\TimeOfficeRequests\Pages\ListTimeOfficeRequests;
use App\Filament\Resources\TimeOfficeRequests\TimeOfficeRequestResource;
use App\Filament\Support\PendingRequestsPanel;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';

/*
| HROne parity RQ-1 (matrix 20 §20.1.0, §20.1.1 #7 / #11 / #15, §20.1.8 #10, §20.1.18, §20.1.19, §20.2.1 #39, §20.3.1 #16,
| §20.3.2 #9 / #24): the request registry, "New request", "My requests", Quick actions, My work and the open-requests panel.
| The registry only reads and links: every request is raised, cancelled and decided through the module's own form/service.
*/

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00'); // a Friday
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create();
    $this->employeeTemplate = config('peopleos.roles.employee.permissions');
    $this->asha = rq1Employee('Asha', $this->employeeTemplate);
    $this->user = $this->asha->user;
    $this->actingAs($this->user);
    $this->registry = fn () => tap(app(RequestRegistry::class))->forget();
    $this->available = fn (?object $user = null) => collect(($this->registry)()->available($user ?? auth()->user()))->map(fn (array $e) => $e['type']->key())->all();
});

/** An employee with a login holding exactly these permissions. */
function rq1Employee(string $first, array $permissions, ?Employee $manager = null): Employee
{
    $user = tenantUser(app(TenantContext::class)->current(), $permissions, ['name' => $first.' Test']);

    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => '2025-01-01', 'user_id' => $user->id],
        ['company_id' => Company::query()->orderBy('id')->value('id')], $manager?->id)->load('user');
}

function rq1Policy(string $type, array $settings): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => Str::title($type).' '.uniqid(), 'code' => 'RQ1_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => [], 'priority' => 10]);

    return $policy;
}

/** Everything the modules need before an employee can raise each of their requests. */
function rq1Prerequisites(): void
{
    LeaveType::query()->where('category', '!=', 'unpaid')->orderBy('id')->first()->update(['is_encashable' => true]);
    SalaryComponent::create(['name' => 'Fuel reimbursement', 'code' => 'FUEL', 'type' => 'reimbursement', 'classification' => 'reimbursement', 'calculation_method' => 'fixed',
        'taxable' => false, 'include_in_ctc' => true, 'include_in_gross' => false, 'is_recurring' => true, 'is_proratable' => false, 'pay_on_claim' => true, 'sort_order' => 50, 'status' => 'active']);
    rq1Policy('expense_advance', ['enabled' => true]);
    rq1Policy('time_office', ['week_off_change_allowed' => 'yes']);
    $calendar = HolidayCalendar::create(['name' => 'India 2026', 'code' => 'IN26', 'year' => 2026, 'optional_requires_selection' => true]);
    HolidayCalendarRule::create(['holiday_calendar_id' => $calendar->id, 'name' => 'All', 'priority' => 1, 'conditions' => [], 'status' => 'active']);
    Holiday::create(['holiday_calendar_id' => $calendar->id, 'name' => 'Onam', 'date' => '2026-10-20', 'type' => 'optional']);
}

/** Create a request row directly (the read model only reads; the modules' own tests cover raising them). */
function rq1Leave(Employee $employee, string $status, string $from = '2026-09-28', array $extra = []): LeaveRequest
{
    return LeaveRequest::create($extra + ['employee_id' => $employee->id, 'leave_type_id' => LeaveType::query()->orderBy('id')->value('id'), 'from_date' => $from, 'to_date' => $from,
        'days' => 1, 'reason' => 'Family function', 'status' => $status]);
}

function rq1TimeOffice(Employee $employee, string $type, string $status, string $from = '2026-09-24', array $extra = []): TimeOfficeRequest
{
    return TimeOfficeRequest::create($extra + ['employee_id' => $employee->id, 'type' => $type, 'status' => $status, 'from_date' => $from, 'to_date' => $from, 'reason' => 'Client visit']);
}

// ---- the registry ------------------------------------------------------------------------------------------------

it('registers one request type per module request, with unique keys and a status map covering every module status', function () {
    $types = app(RequestRegistry::class)->all();

    expect(array_keys($types))->toEqualCanonicalizing([
        'leave', 'leave_encashment', 'attendance_regularisation', 'time_office.on_duty', 'time_office.short_leave', 'time_office.restricted_holiday',
        'time_office.shift_change', 'time_office.week_off_change', 'time_office.overtime', 'time_office.comp_off', 'location',
        'loan', 'reimbursement', 'variable_pay', 'investment_declaration', 'investment_proof', 'expense', 'expense_report', 'expense_advance', 'travel',
        'asset', 'helpdesk', 'hr_service', 'resignation', 'goal_change', 'form',
        // HROne parity MS-3: self-service profile changes (not offered by default: nothing is editable).
        'profile_change',
    ]);
    $statuses = fn (string $model) => array_keys(constant($model.'::STATUSES'));
    foreach ($types as $key => $type) {
        expect($type->label())->not->toBe('')->and($type->module())->not->toBe('')->and($type->permission())->not->toBe('');
        $model = $type->model();
        if ($model === null) {
            expect($key)->toBe('hr_service'); // the service catalogue: its requests are helpdesk tickets

            continue;
        }
        $expected = match ($key) {
            'helpdesk' => array_keys(config('peopleos.servicedesk.statuses')),
            'resignation' => array_keys(config('peopleos.exit.statuses')),
            'goal_change' => ['pending', 'approved', 'rejected'],
            default => $statuses($model),
        };
        expect(array_keys($type->statusMap()))->toContain(...$expected);
        foreach ($type->statusMap() as $mapped) {
            expect($mapped)->toBeInstanceOf(RequestStatus::class);
        }
    }
});

it('offers an employee exactly the requests the modules let them raise, each opening the module\'s own form', function () {
    rq1Prerequisites();
    assignSchedule($this->asha, weeklySchedule(generalShift()));

    $offered = ($this->available)();
    // Not offered: proofs (nothing declared), HR services (no approved service), change goal (no cycle), forms (form.submit).
    expect($offered)->toEqualCanonicalizing([
        'leave', 'leave_encashment', 'attendance_regularisation', 'time_office.on_duty', 'time_office.short_leave', 'time_office.restricted_holiday',
        'time_office.shift_change', 'time_office.week_off_change', 'time_office.overtime', 'time_office.comp_off', 'location',
        'loan', 'reimbursement', 'variable_pay', 'investment_declaration', 'expense', 'expense_report', 'expense_advance', 'travel',
        'asset', 'helpdesk', 'resignation',
    ]);
    $urls = collect(app(RequestRegistry::class)->available($this->user))->mapWithKeys(fn (array $e) => [$e['type']->key() => $e['url']]);
    expect($urls['leave'])->toBe(Home::getUrl().'?action=requestLeave')
        ->and($urls['time_office.on_duty'])->toBe(TimeOfficeRequestResource::getUrl('index').'?'.http_build_query(['action' => 'newRequest', 'actionArguments' => ['type' => 'on_duty']]));

    // Each type disappears for a person without its module permission.
    foreach (app(RequestRegistry::class)->all() as $key => $type) {
        if (! in_array($key, $offered, true)) {
            continue;
        }
        $without = rq1Employee('No'.Str::studly(str_replace('.', '_', $key)), array_values(array_diff($this->employeeTemplate, [$type->permission()])));
        $this->actingAs($without->user);
        expect(($this->available)($without->user))->not->toContain($key);
    }
    // Nobody but the signed-in person gets an answer (destinations check access for the signed-in person).
    $this->actingAs($this->user);
    expect(app(RequestRegistry::class)->available($without->user))->toBe([]);
});

it('follows the module policies and services: time-office switches, encashable types, claim components, advance policy, resignation', function () {
    assignSchedule($this->asha, weeklySchedule(generalShift()));
    LeaveType::query()->update(['is_encashable' => false]);
    $offered = ($this->available)();
    expect($offered)->not->toContain('time_office.week_off_change')   // off unless the time-office policy switches it on
        ->not->toContain('time_office.restricted_holiday')              // no calendar asking to choose optional holidays
        ->not->toContain('leave_encashment')                            // no encashable leave type
        ->not->toContain('reimbursement')                               // no component paid on claim
        ->not->toContain('expense_advance');                            // no advance policy

    // What the launcher hides, the module refuses.
    $requests = app(TimeOfficeRequests::class);
    expect(fn () => $requests->request($this->asha, 'week_off_change', ['from_date' => '2026-09-27', 'swap_date' => '2026-09-30'], 'Swap', $this->user))
        ->toThrow(RuntimeException::class, 'not allowed under your policy');

    rq1Policy('time_office', ['on_duty_allowed' => false, 'overtime_claims_allowed' => false, 'week_off_change_allowed' => 'yes']);
    $offered = ($this->available)();
    expect($offered)->toContain('time_office.week_off_change')->not->toContain('time_office.on_duty')->not->toContain('time_office.overtime')
        ->and(fn () => $requests->request($this->asha, 'on_duty', ['from_date' => '2026-09-24', 'session' => 'full'], 'Client', $this->user))
        ->toThrow(RuntimeException::class, 'On-duty requests are not allowed under your policy.');
    rq1Policy('expense_advance', ['enabled' => false]);
    LeaveType::query()->where('category', '!=', 'unpaid')->orderBy('id')->first()->update(['is_encashable' => true]);
    expect(($this->available)())->not->toContain('expense_advance')->toContain('leave_encashment');

    // Resigning closes the resignation entry (an exit is open).
    expect(($this->available)())->toContain('resignation');
    ExitCase::query()->create(['number' => 'EX-1', 'employee_id' => $this->asha->id, 'type' => 'resignation', 'status' => 'notice', 'initiated_on' => '2026-09-25', 'resignation_date' => '2026-09-25', 'last_working_day' => '2026-10-25']);
    expect(($this->available)())->not->toContain('resignation');
});

it('hides the employee\'s own requests while the employee request lock holds them, and says why', function () {
    $settings = app(SettingsRepository::class);
    $settings->set('inbox.ess_lock.enabled', true, 'test');
    $settings->set('inbox.ess_lock.basis', 'period', 'test');
    $settings->set('inbox.ess_lock.period_from', '2026-09-01', 'test');
    $settings->set('inbox.ess_lock.period_to', '2026-09-30', 'test');
    $settings->set('inbox.ess_lock.request_kinds', ['leave', 'loan'], 'test');

    $offered = ($this->available)();
    expect($offered)->not->toContain('leave')->not->toContain('loan')->toContain('attendance_regularisation')
        ->and(app(RequestRegistry::class)->lockNotices($this->user))->toHaveCount(2)
        ->and(app(RequestRegistry::class)->lockNotices($this->user)[0])->toContain('locked from 01 Sep 2026 to 30 Sep 2026');
});

it('offers form submissions to holders of form.submit, and on-behalf forms to the people the modules allow', function () {
    $hr = tenantUser($this->tenant, ['form.*', 'leave.view', 'leave.manage', 'attendance.view', 'attendance.manage', 'payroll.view', 'payroll.manage']);
    $form = Form::create(['name' => 'Travel', 'key' => 'travel', 'requires_approval' => true]);
    app(Forms::class)->draft($form)->update(['fields' => [['key' => 'destination', 'label' => 'Destination', 'type' => 'text', 'required' => true]]]);
    $this->actingAs($hr);
    app(Forms::class)->publish($form, 'Live');
    app(Forms::class)->submit($form, ['destination' => 'Pune']);

    expect(($this->available)($hr))->toBe(['form']);
    $behalf = collect(app(RequestRegistry::class)->onBehalf($hr))->map(fn (array $e) => $e['type']->key())->all();
    expect($behalf)->toContain('leave', 'attendance_regularisation', 'time_office.on_duty', 'loan', 'variable_pay')
        ->not->toContain('reimbursement')->not->toContain('expense_advance')
        ->and(collect(app(RequestRegistry::class)->onBehalf($hr))->firstWhere(fn ($e) => $e['type']->key() === 'leave')['url'])
        ->toBe(LeaveRequestResource::getUrl('index').'?action=applyOnBehalf');
    // A plain employee may raise nothing for anyone else.
    $this->actingAs($this->user);
    expect(app(RequestRegistry::class)->onBehalf($this->user))->toBe([]);

    // The HR person's own submissions are their requests (they have no employee record of their own) …
    $this->actingAs($hr);
    expect(app(MyRequestsModel::class)->for($hr)['rows']->pluck('type')->all())->toBe(['form'])
        ->and(app(MyRequestsModel::class)->for($hr)['rows'][0]['status'])->toBe(RequestStatus::Pending);
    // … and nobody else is answered about them.
    $this->actingAs($this->user);
    expect(app(MyRequestsModel::class)->for($hr)['rows'])->toHaveCount(0);
});

it('opens the time-office form on the chosen kind, and as before without one', function () {
    $this->actingAs($this->user);
    Livewire::test(ListTimeOfficeRequests::class)->mountAction('newRequest', ['type' => 'on_duty'])
        ->assertActionMounted('newRequest')->assertSchemaStateSet(['type' => 'on_duty']);
    Livewire::test(ListTimeOfficeRequests::class)->mountAction('newRequest')
        ->assertActionMounted('newRequest')->assertSchemaStateSet(['type' => null]);
    Livewire::test(ListTimeOfficeRequests::class)->mountAction('newRequest', ['type' => 'nonsense'])
        ->assertSchemaStateSet(['type' => null]);
});

// ---- My requests -------------------------------------------------------------------------------------------------

function rq1Loan(Employee $employee, string $status, string $number = 'LN-0001'): Loan
{
    return Loan::create(['employee_id' => $employee->id, 'number' => $number, 'loan_type' => 'personal', 'principal' => 20000, 'installments' => 10, 'emi' => 2000,
        'first_due_month' => '2026-10', 'reason' => 'Medical bills', 'status' => $status]);
}

it('lists the person\'s own requests of every type under Pending, Approved, Rejected, Withdrawn and Drafts', function () {
    $pending = rq1Leave($this->asha, 'pending');
    $cancellation = rq1Leave($this->asha, 'cancel_requested', '2026-09-10');
    $onDuty = rq1TimeOffice($this->asha, 'on_duty', 'approved');
    $regularisation = AttendanceRegularisation::create(['employee_id' => $this->asha->id, 'date' => '2026-09-22', 'type' => 'missed_punch', 'reason' => 'Forgot to punch', 'status' => 'rejected']);
    $loan = rq1Loan($this->asha, 'cancelled');
    $receipt = EmployeeTaxDeclaration::create(['employee_id' => $this->asha->id, 'financial_year' => '2026-27', 'regime' => 'old', 'status' => 'draft']);
    rq1Leave(rq1Employee('Ravi', $this->employeeTemplate), 'pending'); // a colleague's request is never mine

    $mine = app(MyRequestsModel::class)->for($this->user);
    expect($mine['counts'])->toMatchArray(['all' => 6, 'pending' => 2, 'approved' => 1, 'rejected' => 1, 'withdrawn' => 1, 'draft' => 1])
        ->and($mine['rows']->pluck('key')->all())->toEqualCanonicalizing(['leave:'.$pending->id, 'leave:'.$cancellation->id, 'time_office.on_duty:'.$onDuty->id,
            'attendance_regularisation:'.$regularisation->id, 'loan:'.$loan->id, 'investment_declaration:'.$receipt->id])
        ->and($mine['rows']->firstWhere('key', 'leave:'.$cancellation->id)['status_label'])->toBe('Cancellation requested')
        ->and($mine['rows']->firstWhere('key', 'loan:'.$loan->id)['status'])->toBe(RequestStatus::Withdrawn)
        ->and($mine['rows']->firstWhere('key', 'leave:'.$pending->id)['url'])->toBe(LeaveRequestResource::getUrl('view', ['record' => $pending]))
        ->and($mine['types']['leave']['count'])->toBe(2);

    $pendingTab = app(MyRequestsModel::class)->for($this->user, null, ['tab' => 'pending']);
    expect($pendingTab['rows']->pluck('type')->unique()->values()->all())->toBe(['leave'])
        ->and(app(MyRequestsModel::class)->for($this->user, null, ['tab' => 'draft'])['rows']->pluck('key')->all())->toBe(['investment_declaration:'.$receipt->id])
        ->and(app(MyRequestsModel::class)->for($this->user, null, ['type' => 'loan'])['rows']->pluck('key')->all())->toBe(['loan:'.$loan->id])
        // The request-type filter keeps offering the other types while one is chosen.
        ->and(app(MyRequestsModel::class)->for($this->user, null, ['type' => 'loan'])['types']['leave']['count'])->toBe(2)
        ->and(app(MyRequestsModel::class)->for($this->user, null, ['type' => 'loan'])['counts']['all'])->toBe(1);
});

it('filters by any of a request\'s dates and by a search, hides closed requests and the ones the person hid', function () {
    $this->travelTo('2026-08-05 10:00:00');
    $august = rq1TimeOffice($this->asha, 'on_duty', 'pending', '2026-08-06');
    $this->travelTo('2026-09-25 10:00:00');
    $wedding = rq1Leave($this->asha, 'approved', '2026-09-10', ['reason' => 'Wedding in Pune']);
    $read = fn (array $filters) => app(MyRequestsModel::class)->for($this->user, null, $filters)['rows']->pluck('key')->all();

    expect($read(['from' => '2026-09-01']))->toBe(['leave:'.$wedding->id])
        ->and($read(['date' => 'effective', 'from' => '2026-08-01', 'until' => '2026-08-31']))->toBe(['time_office.on_duty:'.$august->id])
        ->and($read(['search' => 'pune']))->toBe(['leave:'.$wedding->id])
        ->and($read(['search' => (string) $august->id, 'type' => 'time_office.on_duty']))->toBe(['time_office.on_duty:'.$august->id])
        ->and($read(['hide_closed' => true]))->toBe(['time_office.on_duty:'.$august->id]);

    // "Move to archive": a per-person flag on a closed request; nothing changes on the request itself.
    app(ExperiencePreferences::class)->toggleHiddenRequest($this->user, 'leave:'.$wedding->id);
    $mine = app(MyRequestsModel::class)->for($this->user);
    expect($mine['rows']->pluck('key')->all())->toBe(['time_office.on_duty:'.$august->id])->and($mine['hidden'])->toBe(1)
        ->and(app(MyRequestsModel::class)->for($this->user, null, ['show_hidden' => true])['rows']->firstWhere('key', 'leave:'.$wedding->id)['hidden'])->toBeTrue()
        ->and($wedding->refresh()->status)->toBe('approved');
});

it('keeps requests inside their tenant and the person\'s own employee record (fail closed)', function () {
    $mine = rq1Leave($this->asha, 'pending');
    $other = provisionTenant('Other');
    actAsTenant($other);
    Company::factory()->create();
    $stranger = rq1Employee('Stranger', $this->employeeTemplate);
    rq1Leave($stranger, 'pending');

    // Asha's login asked inside the other tenant: her employee record is not there, so nothing.
    $this->actingAs($this->user);
    expect(app(MyRequestsModel::class)->for($this->user)['rows'])->toHaveCount(0)
        ->and(collect(app(RequestRegistry::class)->available($this->user))->filter(fn ($e) => $e['type']->ownedByEmployee()))->toHaveCount(0);

    // Back home (a fresh login: permission keys are memoised per user instance and roles belong to a tenant).
    actAsTenant($this->tenant);
    app(RequestRegistry::class)->forget();
    $this->actingAs($this->user->fresh());
    expect(app(MyRequestsModel::class)->for(auth()->user())['rows']->pluck('key')->all())->toBe(['leave:'.$mine->id]);
});

it('lets a manager follow a report\'s requests as the modules let them see them, comp-off and encashments included', function () {
    $manager = rq1Employee('Meera', config('peopleos.roles.manager.permissions'));
    $ravi = rq1Employee('Ravi', $this->employeeTemplate, $manager);
    $compOff = rq1TimeOffice($ravi, 'comp_off', 'pending', '2026-09-20');
    $encashment = LeaveEncashment::create(['employee_id' => $ravi->id, 'leave_type_id' => LeaveType::query()->orderBy('id')->value('id'), 'period_year' => 2026, 'days' => 2, 'status' => 'pending', 'reason' => 'Year end']);
    $leave = rq1Leave($ravi, 'approved');
    $ashaLeave = rq1Leave($this->asha, 'pending');

    $this->actingAs($manager->user);
    $followed = app(MyRequestsModel::class)->for($manager->user, $ravi);
    expect($followed['team'])->toBeTrue()
        ->and($followed['rows']->pluck('key')->all())->toContain('time_office.comp_off:'.$compOff->id, 'leave_encashment:'.$encashment->id, 'leave:'.$leave->id)
        ->and($followed['counts']['pending'])->toBe(2)
        // Not their report: nothing.
        ->and(app(MyRequestsModel::class)->for($manager->user, $this->asha)['rows'])->toHaveCount(0)
        ->and(app(MyRequestsModel::class)->team($manager->user)->pluck('id')->all())->toBe([$ravi->id]);

    // The module decides what the manager sees: without leave.view, no leave or encashments.
    $narrow = rq1Employee('Neel', array_values(array_diff(config('peopleos.roles.manager.permissions'), ['leave.view', 'leave.approve'])));
    $kiran = rq1Employee('Kiran', $this->employeeTemplate, $narrow);
    rq1Leave($kiran, 'pending');
    $kiranCompOff = rq1TimeOffice($kiran, 'comp_off', 'pending', '2026-09-20');
    $this->actingAs($narrow->user);
    expect(app(MyRequestsModel::class)->for($narrow->user, $kiran)['rows']->pluck('key')->all())->toBe(['time_office.comp_off:'.$kiranCompOff->id]);

    // An employee cannot follow a colleague.
    $this->actingAs($this->user);
    expect(app(MyRequestsModel::class)->for($this->user, $ravi)['rows'])->toHaveCount(0)
        ->and(app(MyRequestsModel::class)->team($this->user))->toHaveCount(0);

    // The page offers the team member and ignores anyone else.
    $this->actingAs($manager->user);
    Livewire::test(MyRequests::class)->set('employee', $ravi->id)->assertSee('Comp-off')->assertSee('Leave encashment')
        ->set('employee', $this->asha->id)->assertDontSee('Family function');
});

// ---- pages, Quick actions, My work, forms, navigation ------------------------------------------------------------

it('shows "New request" grouped by module, with a search, favourites and the on-behalf forms', function () {
    rq1Prerequisites();
    assignSchedule($this->asha, weeklySchedule(generalShift()));

    $this->get(NewRequest::getUrl())->assertOk()->assertSee('Time office')->assertSee('On duty')->assertSee('Payroll')->assertSee('Loan')
        ->assertSee(e(TimeOfficeRequestResource::getUrl('index').'?'.http_build_query(['action' => 'newRequest', 'actionArguments' => ['type' => 'on_duty']])), false)
        ->assertDontSee('Raise for someone');

    $page = Livewire::test(NewRequest::class)->set('search', 'loan')->assertSee('Loan')->assertDontSee('On duty')->set('search', '');
    $page->call('toggleFavourite', 'loan')->call('toggleFavourite', 'form'); // "form" is not offered to Asha: ignored
    expect(app(ExperiencePreferences::class)->for($this->user)['favourite_requests'])->toBe(['loan'])
        ->and(collect($page->instance()->catalogue()['favourites'])->pluck('key')->all())->toBe(['loan']);
    // At most twelve favourites; unknown keys never stored.
    app(ExperiencePreferences::class)->update($this->user, ['favourite_requests' => [...array_map(fn ($i) => 'k'.$i, range(1, 14)), 'Bad Key!']]);
    expect(app(ExperiencePreferences::class)->for($this->user)['favourite_requests'])->toHaveCount(12)->not->toContain('Bad Key!');

    // HR raising for someone sees the module on-behalf forms; a login with neither an employee record nor such a right is refused.
    $hr = tenantUser($this->tenant, ['leave.view', 'leave.manage']);
    $this->actingAs($hr);
    $this->get(NewRequest::getUrl())->assertOk()->assertSee('Raise for someone')->assertSee(e(LeaveRequestResource::getUrl('index').'?action=applyOnBehalf'), false);
    $this->actingAs(tenantUser($this->tenant, ['company.view']));
    $this->get(NewRequest::getUrl())->assertForbidden();
    $this->get(MyRequests::getUrl())->assertForbidden();
});

it('shows "My requests" with its tabs and filters, a hide flag on closed requests only, and the New request button', function () {
    $pending = rq1Leave($this->asha, 'pending');
    $loan = rq1Loan($this->asha, 'rejected');

    $this->get(MyRequests::getUrl())->assertOk()->assertSee('New request')->assertSee('LN-0001')->assertSee('Family function');
    $page = Livewire::test(MyRequests::class)->call('setTab', 'pending')->assertSee('Family function')->assertDontSee('LN-0001')
        ->call('setTab', 'all')->set('type', 'loan')->assertSee('LN-0001')->assertDontSee('Family function')->call('clearFilters');
    $page->call('toggleHidden', 'leave:'.$pending->id); // still pending: cannot be hidden
    $page->call('toggleHidden', 'loan:'.$loan->id);
    expect(app(ExperiencePreferences::class)->for($this->user)['hidden_requests'])->toBe(['loan:'.$loan->id]);
    $page->assertDontSee('LN-0001')->set('showHidden', true)->assertSee('LN-0001');
});

it('makes every request type a Quick action without changing Home\'s featured actions', function () {
    rq1Prerequisites();
    assignSchedule($this->asha, weeklySchedule(generalShift()));
    $actions = collect(app(QuickActions::class)->for($this->user));

    expect($actions->pluck('key')->all())->toContain('new_request', 'my_requests', 'request.loan', 'request.time_office.on_duty', 'request.travel', 'request.asset')
        ->not->toContain('request.leave')->not->toContain('request.attendance_regularisation')->not->toContain('request.helpdesk')
        ->and(collect(app(QuickActions::class)->forLens($this->user, 'employee', 6))->pluck('key')->all())->toBe(['request_leave', 'regularise', 'ask_hr', 'services', 'my_documents', 'bank_details'])
        ->and($actions->firstWhere('key', 'request.loan')['url'])->toBe(LoanResource::getUrl('index').'?action=requestLoan');
    // Never featured on Home, even for a person with few actions of their own.
    $minimal = rq1Employee('Mini', ['employee.self']);
    $this->actingAs($minimal->user);
    expect(collect(app(QuickActions::class)->for($minimal->user))->pluck('key')->all())->toContain('new_request', 'my_requests')
        ->and(collect(app(QuickActions::class)->forLens($minimal->user, 'employee', 6))->pluck('key')->filter(fn (string $key) => QuickActions::isRequestLauncher($key)))->toHaveCount(0);
    $this->actingAs($this->user);
    // The Command Center finds them by name.
    $found = collect(app(CommandSearch::class)->search($this->user, 'loan', 'actions'))->flatMap(fn ($g) => $g['items'])->pluck('title')->all();
    expect($found)->toContain('Loan');
});

it('adds the person\'s other pending requests to My work, once each', function () {
    $loan = rq1Loan($this->asha, 'pending');
    $onDuty = rq1TimeOffice($this->asha, 'on_duty', 'pending');
    $leave = rq1Leave($this->asha, 'pending');
    rq1Loan($this->asha, 'approved', 'LN-0002');

    $waiting = app(WorkInbox::class)->for($this->user)['waiting'];
    expect($waiting->pluck('key')->all())->toContain('mine-loan:'.$loan->id, 'mine-time_office.on_duty:'.$onDuty->id, 'mine-leave:'.$leave->id)
        ->and($waiting->where('key', 'mine-leave:'.$leave->id))->toHaveCount(1)
        ->and($waiting)->toHaveCount(3);
});

it('shows the person\'s open time-office requests inside their own request forms only', function () {
    rq1Leave($this->asha, 'pending');
    rq1TimeOffice($this->asha, 'on_duty', 'pending', '2026-09-24', ['category' => 'Client visit']);
    rq1Loan($this->asha, 'pending');
    $ravi = rq1Employee('Ravi', $this->employeeTemplate);

    $html = PendingRequestsPanel::render('time_office')->toHtml();
    expect($html)->toContain('On duty · 24 Sep 2026')->toContain('Pending')->not->toContain('LN-0001')
        ->and(PendingRequestsPanel::isOwn($this->asha))->toBeTrue()
        ->and(PendingRequestsPanel::isOwn($ravi))->toBeFalse()
        ->and(PendingRequestsPanel::isOwn(new Employee))->toBeFalse()
        ->and(PendingRequestsPanel::isOwn(null))->toBeFalse();
    // The forms still submit as before (the panel is read only and never part of the data).
    assignSchedule($this->asha, weeklySchedule(generalShift()));
    Livewire::test(ListTimeOfficeRequests::class)->callAction('newRequest', ['type' => 'on_duty', 'from_date' => '2026-09-23', 'session' => 'full', 'reason' => 'Client'])
        ->assertHasNoActionErrors();
    expect(TimeOfficeRequest::query()->where('employee_id', $this->asha->id)->whereDate('from_date', '2026-09-23')->exists())->toBeTrue();
});

it('puts My requests and New request in the My work rail', function () {
    $this->get(MyRequests::getUrl())->assertOk();
    $labels = array_column(app(ExperienceNavigation::class)->children($this->user, 'work'), 'label');
    expect($labels)->toContain('Inbox', 'My requests', 'New request');
});

// ---- HR bulk cancel ----------------------------------------------------------------------------------------------

it('lets HR cancel several leave requests and claims at once through the module services, refusing what cannot be cancelled', function () {
    $hr = tenantUser($this->tenant, ['bulk.run', 'employee.view', 'leave.view', 'leave.manage', 'payroll.view', 'payroll.manage']);
    $open = rq1Leave($this->asha, 'pending');
    $done = rq1Leave($this->asha, 'cancelled', '2026-09-11');
    $component = SalaryComponent::create(['name' => 'Fuel reimbursement', 'code' => 'FUEL', 'type' => 'reimbursement', 'classification' => 'reimbursement', 'calculation_method' => 'fixed',
        'taxable' => false, 'include_in_ctc' => true, 'include_in_gross' => false, 'is_recurring' => true, 'is_proratable' => false, 'pay_on_claim' => true, 'sort_order' => 50, 'status' => 'active']);
    $claim = fn (string $status) => ReimbursementClaim::create(['employee_id' => $this->asha->id, 'salary_component_id' => $component->id, 'financial_year' => 2026, 'claim_date' => '2026-09-20',
        'amount' => 500, 'description' => 'Fuel', 'status' => $status]);
    [$pendingClaim, $paidClaim] = [$claim('pending'), $claim('paid')];

    $this->actingAs($hr);
    $run = app(BulkRuns::class)->start($hr, 'leave_requests', 'leave_requests.cancel', [$open->id, $done->id], ['reason' => 'Office closed']);
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($run->done)->toBe(1)->and($run->refused)->toBe(1)
        ->and($open->refresh()->status)->toBe('cancelled')
        ->and($items[$done->id]->message)->toContain('Only leave still pending or approved');

    $run = app(BulkRuns::class)->start($hr, 'reimbursement_claims', 'reimbursement_claims.cancel', [$pendingClaim->id, $paidClaim->id], ['reason' => 'Duplicate']);
    expect($run->done)->toBe(1)->and($run->refused)->toBe(1)
        ->and($pendingClaim->refresh()->status)->toBe('cancelled')->and($paidClaim->refresh()->status)->toBe('paid')
        ->and(LeaveRequest::query()->whereKey($open->id)->exists())->toBeTrue(); // nothing is deleted

    // Without bulk.run, or without the module's own right, nothing starts.
    $noBulk = tenantUser($this->tenant, ['leave.view', 'leave.manage']);
    $noLeave = tenantUser($this->tenant, ['bulk.run', 'leave.view']);
    expect(fn () => app(BulkRuns::class)->start($noBulk, 'leave_requests', 'leave_requests.cancel', [$open->id], ['reason' => 'x']))->toThrow(RuntimeException::class)
        ->and(fn () => app(BulkRuns::class)->start($noLeave, 'leave_requests', 'leave_requests.cancel', [$open->id], ['reason' => 'x']))->toThrow(RuntimeException::class)
        ->and(app(BulkOperations::class)->find('leave_requests.cancel'))->toBeInstanceOf(CancelLeaveOperation::class)
        ->and(app(BulkOperations::class)->find('reimbursement_claims.cancel'))->toBeInstanceOf(CancelClaimOperation::class);
});
