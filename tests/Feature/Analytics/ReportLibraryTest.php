<?php

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Bgv\Models\BgvCheck;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Actions\TransferEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Exit\Models\FinalSettlementLine;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Letters\Models\Letter;
use App\Domain\Lifecycle\Models\EmployeeLifecycleTransition;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\People\Models\PersonEmergencyContact;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Models\PersonQualification;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Filament\Pages\ReportLibraryPage;
use App\Filament\Resources\Reports\ReportResource;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/** Save a fixture row as the domain would, without depending on each model's fillable list. */
function reportFixture(string $class, array $attributes): Model
{
    $model = new $class;
    $model->forceFill($attributes)->save();

    return $model;
}

/** One or more rows behind every dataset, so every template exercises its value closures. */
function seedReportLibraryFixtures(Employee $a, Employee $b, User $hr, User $approver): array
{
    $eng = Department::factory()->create(['name' => 'Engineering', 'code' => 'ENG']);
    $pune = Location::factory()->create(['name' => 'Pune', 'company_id' => $a->currentPosition->company_id]);
    app(TransferEmployeeAction::class)->handle($a, ['department_id' => $eng->id, 'location_id' => $pune->id], '2026-09-01', null, 'Relocation');

    EmployeeStatutoryDetail::query()->updateOrCreate(['employee_id' => $a->id], ['pan' => 'ABCDE1234F', 'uan' => '100200300400', 'esic_number' => '3100200300', 'pf_applicable' => true, 'esic_applicable' => true, 'pt_applicable' => true, 'tax_regime' => 'new']);
    foreach ([$a, $b] as $employee) {
        EmployeeBankAccount::query()->firstOrCreate(['employee_id' => $employee->id], ['account_holder_name' => 'X', 'bank_name' => 'HDFC Bank', 'account_number' => '50100123456789', 'ifsc' => 'HDFC0000001', 'account_type' => 'savings', 'is_primary' => true]);
    }
    reportFixture(PersonFamilyMember::class, ['person_id' => $a->person_id, 'name' => 'Asha', 'relation' => 'spouse', 'date_of_birth' => '1990-05-01', 'gender' => 'female', 'is_dependent' => true, 'is_nominee' => true, 'nominee_share' => 100]);
    reportFixture(PersonEmergencyContact::class, ['person_id' => $a->person_id, 'name' => 'Asha', 'relation' => 'spouse', 'phone' => '9800000000', 'priority' => 1]);
    reportFixture(PersonQualification::class, ['person_id' => $a->person_id, 'qualification' => 'B.Tech', 'specialisation' => 'CS', 'year_of_completion' => 2012]);
    $passport = DocumentType::query()->firstOrCreate(['code' => 'RPT_PASSPORT'], ['name' => 'Passport', 'category' => 'identity', 'requires_expiry' => true]);
    reportFixture(EmployeeDocument::class, ['employee_id' => $a->id, 'document_type_id' => $passport->id, 'title' => 'Passport', 'disk' => 'local', 'path' => 'x.pdf', 'original_name' => 'x.pdf', 'version' => 1, 'status' => 'pending', 'expires_on' => '2026-11-01']);
    reportFixture(Letter::class, ['number' => 'LTR-0001', 'employee_id' => $a->id, 'type' => 'confirmation', 'subject' => 'Confirmation', 'body' => 'Confirmed.', 'status' => 'issued', 'issued_at' => now()]);
    reportFixture(EmployeeLifecycleTransition::class, ['employee_id' => $a->id, 'from_state' => 'probation', 'to_state' => 'confirmed', 'effective_date' => '2026-10-01', 'actor_id' => $hr->id]);

    foreach (['2026-09-28' => 'present', '2026-09-29' => 'absent', '2026-10-13' => 'present', '2026-10-14' => 'incomplete', '2026-10-15' => 'wfh'] as $date => $status) {
        AttendanceRecord::create(['employee_id' => $a->id, 'date' => $date, 'status' => $status, 'first_in' => "{$date} 09:20:00", 'last_out' => "{$date} 17:30:00", 'worked_minutes' => 490, 'late_minutes' => $status === 'present' ? 20 : 0, 'early_leave_minutes' => 0, 'overtime_minutes' => 30, 'overtime_approved_minutes' => 30, 'exceptions' => $status === 'incomplete' ? ['missing_out'] : [], 'processed_at' => now()]);
    }
    reportFixture(AttendancePunch::class, ['employee_id' => $a->id, 'punched_at' => now(), 'direction' => 'in', 'source' => 'web', 'processing_status' => 'failed', 'processing_error' => 'No shift', 'received_at' => now()]);
    reportFixture(AttendanceRegularisation::class, ['employee_id' => $a->id, 'date' => '2026-10-14', 'type' => 'missed_punch', 'requested_out' => '2026-10-14 18:00:00', 'reason' => 'Forgot to punch out', 'status' => 'pending', 'requested_by' => $hr->id]);
    $calendar = reportFixture(HolidayCalendar::class, ['name' => 'India 2026', 'code' => 'IN-2026', 'year' => 2026, 'status' => 'active']);
    reportFixture(Holiday::class, ['holiday_calendar_id' => $calendar->id, 'date' => '2026-10-20', 'name' => 'Diwali', 'type' => 'public', 'is_half_day' => false]);

    $casual = LeaveType::query()->firstOrCreate(['code' => 'RPT_CL'], ['name' => 'Casual leave', 'unit' => 'day', 'is_paid' => true, 'status' => 'active', 'effective_from' => '2026-01-01']);
    $unpaid = LeaveType::query()->firstOrCreate(['code' => 'RPT_LWP'], ['name' => 'Leave without pay', 'unit' => 'day', 'is_paid' => false, 'status' => 'active', 'effective_from' => '2026-01-01']);
    reportFixture(LeaveLedgerEntry::class, ['employee_id' => $a->id, 'leave_type_id' => $casual->id, 'period_year' => 2026, 'entry_date' => '2026-01-01', 'type' => 'accrual', 'days' => 12]);
    reportFixture(LeaveLedgerEntry::class, ['employee_id' => $a->id, 'leave_type_id' => $casual->id, 'period_year' => 2026, 'entry_date' => '2026-03-02', 'type' => 'usage', 'days' => -2]);
    reportFixture(LeaveLedgerEntry::class, ['employee_id' => $a->id, 'leave_type_id' => $casual->id, 'period_year' => 2025, 'entry_date' => '2025-12-31', 'type' => 'lapse', 'days' => -3]);
    reportFixture(LeaveRequest::class, ['employee_id' => $a->id, 'leave_type_id' => $casual->id, 'from_date' => '2026-10-15', 'to_date' => '2026-10-15', 'days' => 1, 'dates' => [['date' => '2026-10-15', 'days' => 1]], 'from_session' => 'full', 'to_session' => 'full', 'reason' => 'Personal', 'status' => 'approved', 'reviewed_by' => $hr->id, 'reviewed_at' => now()]);
    reportFixture(LeaveRequest::class, ['employee_id' => $a->id, 'leave_type_id' => $casual->id, 'from_date' => '2026-10-26', 'to_date' => '2026-10-27', 'days' => 2, 'dates' => [['date' => '2026-10-26', 'days' => 1], ['date' => '2026-10-27', 'days' => 1]], 'from_session' => 'full', 'to_session' => 'full', 'reason' => 'Trip', 'status' => 'pending']);
    reportFixture(LeaveRequest::class, ['employee_id' => $b->id, 'leave_type_id' => $unpaid->id, 'from_date' => '2026-10-05', 'to_date' => '2026-10-05', 'days' => 1, 'dates' => [['date' => '2026-10-05', 'days' => 1]], 'from_session' => 'full', 'to_session' => 'full', 'reason' => 'Unwell', 'status' => 'approved']);
    app(LeaveBalances::class)->recompute($a, $casual, 2026);
    reportFixture(LeaveEncashment::class, ['employee_id' => $a->id, 'leave_type_id' => $casual->id, 'period_year' => 2026, 'days' => 2, 'status' => 'pending', 'reason' => 'Year end']);

    $company = $a->currentPosition->company;
    $period = PayrollPeriod::for($company, 2026, 9);
    reportFixture(PayrollAdjustment::class, ['employee_id' => $a->id, 'payroll_period_id' => $period->id, 'type' => 'arrear', 'name' => 'August arrear', 'amount' => 1500, 'taxable' => true, 'status' => 'approved', 'approved_by' => $hr->id, 'approved_at' => now()]);
    $runs = app(PayrollRuns::class);
    $runs->finalize($runs->approve($runs->validate($runs->calculate($runs->open($company, 2026, 9))), $approver), $approver);

    $declaration = reportFixture(EmployeeTaxDeclaration::class, ['employee_id' => $a->id, 'financial_year' => '2026-27', 'regime' => 'old', 'declarations' => ['80C' => 150000], 'status' => 'submitted']);
    reportFixture(TdsEmployeeInvestment::class, ['employee_id' => $a->id, 'employee_tax_declaration_id' => $declaration->id, 'financial_year' => '2026-27', 'section' => '80C', 'item_key' => 'ppf', 'description' => 'PPF', 'declared_amount' => 100000, 'proof_amount' => 100000, 'proof_status' => 'pending']);

    $plan = reportFixture(OnboardingPlan::class, ['employee_id' => $b->id, 'status' => 'in_progress', 'anchor_date' => '2026-10-01', 'progress' => 50, 'started_at' => now()]);
    reportFixture(OnboardingTask::class, ['onboarding_plan_id' => $plan->id, 'phase' => 'day_one', 'type' => 'document', 'title' => 'Upload PAN', 'due_on' => '2026-10-10', 'is_mandatory' => true, 'status' => 'pending', 'sort_order' => 1, 'document_type_id' => $passport->id]);
    $bgv = reportFixture(BgvCase::class, ['employee_id' => $b->id, 'provider' => 'manual', 'status' => 'in_progress', 'overall_result' => 'pending', 'initiated_at' => now()->subDays(5)]);
    reportFixture(BgvCheck::class, ['bgv_case_id' => $bgv->id, 'type' => 'education', 'status' => 'pending']);

    $exit = reportFixture(ExitCase::class, ['number' => 'EXIT-2026-00001', 'employee_id' => $b->id, 'type' => 'resignation', 'status' => 'notice', 'initiated_on' => '2026-10-01', 'resignation_date' => '2026-10-01', 'notice_days' => 30, 'last_working_day' => '2026-10-31', 'manager_id' => $a->id]);
    reportFixture(ExitClearance::class, ['exit_case_id' => $exit->id, 'stage' => 'department', 'name' => 'IT assets', 'status' => 'pending', 'items' => [], 'sort_order' => 1, 'owner_user_id' => $hr->id]);
    $settlement = reportFixture(FinalSettlement::class, ['exit_case_id' => $exit->id, 'employee_id' => $b->id, 'status' => 'calculated', 'total_earnings' => 10000, 'total_deductions' => 500, 'net_amount' => 9500, 'calculated_at' => now()]);
    reportFixture(FinalSettlementLine::class, ['final_settlement_id' => $settlement->id, 'type' => 'earning', 'code' => 'LEAVE_ENCASHMENT', 'name' => 'Leave encashment', 'amount' => 4000, 'sort_order' => 1]);

    $cycle = draftCycle();
    reportFixture(Goal::class, ['level' => 'employee', 'employee_id' => $a->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Ship the release', 'weight' => 50, 'progress' => 20, 'status' => 'active', 'due_date' => '2026-10-01']);
    reportFixture(ImprovementPlan::class, ['employee_id' => $b->id, 'manager_id' => $a->id, 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'reason' => 'Targets missed', 'objectives' => [], 'status' => 'active']);

    return ['casual' => $casual];
}

beforeEach(function () {
    $this->travelTo('2026-10-15 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->hr);
    payrollCompany();
    $this->a = salariedEmployee(600000);
    $this->b = salariedEmployee(900000, ['task.view'], '2025-01-01', ['CONV' => 1600], $this->a);
});

it('builds every library template from real fields and runs it for an HR admin', function () {
    $fixtures = seedReportLibraryFixtures($this->a, $this->b, $this->hr, $this->approver);
    $registry = app(DatasetRegistry::class);
    $library = app(ReportLibrary::class)->all();

    expect(count($library))->toBeGreaterThan(100)
        ->and(collect($library)->pluck('key')->duplicates())->toBeEmpty()
        ->and(collect($library)->pluck('category')->unique()->diff(ReportLibrary::CATEGORIES))->toBeEmpty();

    foreach ($library as $template) {
        expect($registry->has($template['dataset']))->toBeTrue($template['key']);
        $fields = $registry->get($template['dataset'])->fields();
        $definition = $template['definition'];
        $aggregates = collect($definition['aggregations'] ?? [])->map(fn ($a) => $a['fn'] === 'count' ? 'count' : $a['fn'].'_'.$a['field']);
        $referenced = collect($definition['fields'])->merge(collect($definition['filters'])->pluck('field'))->merge(collect($definition['aggregations'] ?? [])->pluck('field'))->push($definition['group_by'] ?? null)->filter();
        foreach ($referenced as $field) {
            expect($fields)->toHaveKey($field, message: "{$template['key']} uses unknown field {$field}");
        }
        foreach ($definition['filters'] as $filter) {
            expect(config('peopleos.analytics.operators'))->toHaveKey($filter['operator'], message: "{$template['key']} uses unknown operator {$filter['operator']}");
        }
        if ($sort = $definition['sort']['field'] ?? null) {
            expect(isset($fields[$sort]) || $aggregates->contains($sort))->toBeTrue("{$template['key']} sorts on {$sort}");
        }

        $result = app(ReportRunner::class)->execute($template['dataset'], $definition, $this->hr);
        expect($result->columns)->not->toBeEmpty($template['key']);
    }

    // Every dataset, every field, over the fixtures: no closure may touch an unloaded relation or attribute.
    foreach ($registry->all() as $key => $dataset) {
        expect(fn () => app(ReportRunner::class)->execute($key, [], $this->hr))->not->toThrow(Throwable::class);
    }

    $balance = app(ReportRunner::class)->execute('leave_balances', app(ReportLibrary::class)->find('leave_balance')['definition'], $this->hr);
    expect($balance->rows)->toHaveCount(1)->and($balance->rows[0])->toMatchArray(['leave_type' => 'Casual leave', 'opening' => 0.0, 'accrued' => 12.0, 'used' => 2.0, 'closing' => 10.0, 'pending' => 2.0, 'available' => 8.0]);
    expect($fixtures['casual']->id)->toBeInt();
});

it('rolls attendance up per employee and month, and lists late coming, missed punches and leave', function () {
    seedReportLibraryFixtures($this->a, $this->b, $this->hr, $this->approver);
    $runner = app(ReportRunner::class);
    $library = app(ReportLibrary::class);

    $monthly = $runner->execute('attendance_monthly', ['fields' => ['month', 'employee_code', 'days_recorded', 'present_days', 'absent_days', 'missed_punch_days', 'wfh_days', 'days_worked', 'late_count', 'late_minutes', 'worked_hours', 'overtime_hours'], 'sort' => ['field' => 'month', 'dir' => 'asc']], $this->hr);
    expect($monthly->rows)->toBe([
        ['month' => '2026-09', 'employee_code' => $this->a->employee_code, 'days_recorded' => 2, 'present_days' => 1, 'absent_days' => 1, 'missed_punch_days' => 0, 'wfh_days' => 0, 'days_worked' => 1.0, 'late_count' => 1, 'late_minutes' => 20, 'worked_hours' => 16.33, 'overtime_hours' => 1.0],
        ['month' => '2026-10', 'employee_code' => $this->a->employee_code, 'days_recorded' => 3, 'present_days' => 1, 'absent_days' => 0, 'missed_punch_days' => 1, 'wfh_days' => 1, 'days_worked' => 2.0, 'late_count' => 1, 'late_minutes' => 20, 'worked_hours' => 24.5, 'overtime_hours' => 1.5],
    ]);
    expect($runner->execute('attendance_monthly', $library->find('monthly_summary_last_month')['definition'], $this->hr)->rows)->toHaveCount(1)
        ->and($runner->execute('attendance', $library->find('late_coming_this_month')['definition'], $this->hr)->rows[0]['first_in'])->toBe('09:20')
        ->and($runner->execute('attendance', $library->find('missed_punches_this_month')['definition'], $this->hr)->rows[0]['date'])->toBe('2026-10-14')
        ->and(collect($runner->execute('leave', $library->find('on_leave_today')['definition'], $this->hr)->rows)->pluck('employee_code')->all())->toBe([$this->a->employee_code])
        ->and(collect($runner->execute('leave', $library->find('lop_leave_this_month')['definition'], $this->hr)->rows)->pluck('employee_code')->all())->toBe([$this->b->employee_code])
        ->and($runner->execute('leave', $library->find('pending_leave_approvals')['definition'], $this->hr)->rows)->toHaveCount(1)
        ->and(collect($runner->execute('leave', $library->find('leave_availed_by_employee')['definition'], $this->hr)->rows)->pluck('employee'))->toContain($this->a->employee_code.' · '.$this->a->person->full_name)
        ->and(collect($runner->execute('leave_ledger', $library->find('lapsed_leave')['definition'], $this->hr)->rows)->pluck('debit')->all())->toBe([3.0]);

    $ctc = $runner->execute('ctc_register', $library->find('ctc_register')['definition'], $this->hr);
    expect(collect($ctc->rows)->pluck('ctc_annual', 'employee_code')->all())->toEqual([$this->a->employee_code => 600000, $this->b->employee_code => 900000])
        ->and($runner->execute('ctc_register', $library->find('employees_without_salary')['definition'], $this->hr)->total)->toBe(0);

    $register = $runner->execute('payroll', $library->find('salary_register_last_month')['definition'], $this->hr);
    expect($register->rows)->toHaveCount(2)->and(collect($register->rows)->every(fn ($r) => $r['gross'] > 0 && $r['net_pay'] > 0))->toBeTrue();
    $components = $runner->execute('payroll_components', $library->find('component_register_last_month')['definition'], $this->hr);
    expect(collect($components->rows)->pluck('component_code'))->toContain('PF_EE')
        ->and(collect($components->rows)->where('employee_code', $this->a->employee_code)->where('component_type', 'Earning')->sum('amount'))->toBe(collect($register->rows)->firstWhere('employee_code', $this->a->employee_code)['gross']);
});

it('masks statutory identifiers and bank accounts and keeps them behind the sensitive permission', function () {
    seedReportLibraryFixtures($this->a, $this->b, $this->hr, $this->approver);
    $runner = app(ReportRunner::class);

    $row = collect($runner->execute('statutory_bank', ['fields' => ['employee_code', 'pan', 'uan', 'esic_number', 'account_number', 'has_pan', 'has_aadhaar']], $this->hr)->rows)->firstWhere('employee_code', $this->a->employee_code);
    expect($row)->toBe(['employee_code' => $this->a->employee_code, 'pan' => '••••••234F', 'uan' => '••••••••0400', 'esic_number' => '••••••0300', 'account_number' => '••••6789', 'has_pan' => true, 'has_aadhaar' => false]);
    $bank = $runner->execute('payroll', app(ReportLibrary::class)->find('bank_transfer_last_month')['definition'], $this->hr);
    expect(json_encode($bank->rows))->not->toContain('50100123456789')->and(collect($bank->rows)->pluck('account_number')->unique()->all())->toBe(['••••6789']);

    $payrollOnly = tenantUser($this->tenant, ['payroll.view', 'analytics.reports']);
    expect(app(DatasetRegistry::class)->get('statutory_bank')->allowedFor($payrollOnly))->toBeFalse()
        ->and(app(DatasetRegistry::class)->get('family_members')->allowedFor($payrollOnly))->toBeFalse()
        ->and(array_keys($runner->execute('payroll', ['fields' => ['employee_code', 'uan', 'account_number', 'net_pay']], $payrollOnly)->columns))->toBe(['employee_code', 'net_pay']);
});

it('keeps child rows of unscoped models inside the viewer access scope and users inside the tenant', function () {
    seedReportLibraryFixtures($this->a, $this->b, $this->hr, $this->approver);
    $runner = app(ReportRunner::class);
    $scoped = tenantUser($this->tenant, ['payroll.view', 'exit.view', 'onboarding.view', 'employee.view', 'employee.sensitive.view', 'analytics.reports']);
    app(AccessScopes::class)->assign($scoped, ['location' => [Location::factory()->create(['company_id' => $this->a->currentPosition->company_id])->id]]);
    $this->actingAs($scoped);

    foreach (['payroll_components', 'exit_clearances', 'onboarding_tasks', 'family_members', 'emergency_contacts', 'qualifications', 'payroll', 'statutory_bank'] as $dataset) {
        expect($runner->execute($dataset, ['fields' => ['employee_code']], $scoped)->total)->toBe(0, $dataset);
    }
    $this->actingAs($this->hr);
    expect($runner->execute('payroll_components', ['fields' => ['employee_code']], $this->hr)->total)->toBeGreaterThan(0)
        ->and($runner->execute('family_members', ['fields' => ['employee_code']], $this->hr)->total)->toBe(1);

    $other = provisionTenant('Other');
    actAsTenant($other);
    $stranger = tenantUser($other, ['user.view']);
    actAsTenant($this->tenant);
    $emails = collect($runner->execute('users', ['fields' => ['email']], $this->hr)->rows)->pluck('email');
    expect($emails)->toContain($this->hr->email)->not->toContain($stranger->email);
});

it('filters on relative dates, financial years and exclusion lists', function () {
    $runner = app(ReportRunner::class);
    foreach (['2026-10-15' => 'present', '2026-10-14' => 'absent', '2026-10-19' => 'present', '2026-09-30' => 'leave', '2026-04-01' => 'present', '2026-03-31' => 'present'] as $date => $status) {
        AttendanceRecord::create(['employee_id' => $this->a->id, 'date' => $date, 'status' => $status, 'processed_at' => now()]);
    }
    $dates = fn (string $operator, ?string $value = null) => collect($runner->execute('attendance', ['fields' => ['date'], 'filters' => [['field' => 'date', 'operator' => $operator, 'value' => $value]], 'sort' => ['field' => 'date', 'dir' => 'asc']], $this->hr)->rows)->pluck('date')->all();

    expect($dates('today'))->toBe(['2026-10-15'])
        ->and($dates('yesterday'))->toBe(['2026-10-14'])
        ->and($dates('next_days', '7'))->toBe(['2026-10-15', '2026-10-19'])
        ->and($dates('this_week'))->toBe(['2026-10-14', '2026-10-15'])
        ->and($dates('last_month'))->toBe(['2026-09-30'])
        ->and($dates('this_financial_year'))->toBe(['2026-04-01', '2026-09-30', '2026-10-14', '2026-10-15', '2026-10-19'])
        ->and($dates('last_financial_year'))->toBe(['2026-03-31']);
    expect($runner->execute('attendance', ['fields' => ['date'], 'filters' => [['field' => 'status', 'operator' => 'not_in', 'value' => 'present,leave']]], $this->hr)->total)->toBe(1);
});

it('creates a report from a template, optionally for one employee, within the user rights', function () {
    $library = app(ReportLibrary::class);

    $report = $library->create('leave_balance', $this->hr, null, $this->a->employee_code);
    expect($report->name)->toBe('Leave balance (current leave year) — '.$this->a->employee_code)
        ->and($report->dataset)->toBe('leave_balances')->and($report->owner_id)->toBe($this->hr->id)->and($report->is_shared)->toBeFalse()
        ->and($report->def('filters'))->toContain(['field' => 'employee_code', 'operator' => 'equals', 'value' => $this->a->employee_code]);

    $attendanceOnly = tenantUser($this->tenant, ['attendance.view', 'analytics.reports']);
    expect(collect($library->availableTo($attendanceOnly))->keys()->all())->toBe(['Attendance'])
        ->and($library->availableTo($this->hr, 'leave balance')->flatten(1)->pluck('key')->all())->toContain('leave_balance', 'leave_balance_by_type')
        ->and(fn () => $library->create('leave_balance', $attendanceOnly))->toThrow(RuntimeException::class, 'cannot report')
        ->and(fn () => $library->create('attendance_today', $attendanceOnly, shared: true))->toThrow(RuntimeException::class, 'Only analytics managers')
        ->and(fn () => $library->create('holiday_list', $attendanceOnly, employeeCode: 'E1'))->toThrow(RuntimeException::class, 'cannot be narrowed');
});

it('renders the report library and turns a template into a report', function () {
    $this->get(ReportLibraryPage::getUrl())->assertOk()->assertSee('Leave balance (current leave year)')->assertSee('Salary register (last month)')->assertSee('Monthly attendance summary (this month)');
    $this->get(ReportResource::getUrl('index'))->assertOk()->assertSee('Report library');

    Livewire::test(ReportLibraryPage::class)
        ->set('search', 'punch log')->assertSee('Punch log (today)')->assertDontSee('Leave balance (current leave year)')
        ->callAction('useTemplate', ['name' => 'My punches', 'employee_code' => $this->a->employee_code], ['template' => 'punch_log_today'])
        ->assertHasNoActionErrors()
        ->assertRedirect(ReportResource::getUrl('view', ['record' => Report::query()->where('name', 'My punches')->firstOrFail()]));

    $this->actingAs(tenantUser($this->tenant, ['analytics.view']));
    $this->get(ReportLibraryPage::getUrl())->assertForbidden();
});
