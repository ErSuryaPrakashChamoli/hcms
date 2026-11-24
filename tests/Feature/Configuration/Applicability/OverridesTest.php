<?php

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Models\WorkScheduleAssignment;
use App\Domain\Attendance\Services\ShiftResolver;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Configuration\Overrides\SettingsPath;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveEntitlements;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| Entity-specific overrides (docs/architecture/configuration-applicability.md, acceptance 13.2 and Part 17 "Entity-specific
| override"): one leave policy shared by Markedge and FynnEdge, 18 days of annual leave; FynnEdge gives itself 24 annual and
| 8 sick days without touching Markedge's. The same for an attendance grace period and a shift's own grace.
*/

beforeEach(function () {
    $this->travelTo('2026-11-23 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->overrides = app(ConfigurationOverrides::class);

    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->th = Company::factory()->create(['name' => 'Third Labs', 'code' => 'TH']);
    foreach (['AL' => 'Annual leave', 'SL' => 'Sick leave', 'CL' => 'Casual leave'] as $code => $name) {
        LeaveType::query()->firstOrCreate(['code' => $code], ['name' => $name]);
    }

    $policies = app(Policies::class);
    $this->leave = Policy::create(['type' => 'leave', 'name' => 'Group leave', 'code' => 'GRP_LEAVE']);
    $policies->draft($this->leave, ['entitlements' => [
        ['leave_type_code' => 'AL', 'days' => 18], ['leave_type_code' => 'SL', 'days' => 6], ['leave_type_code' => 'CL', 'days' => 6],
    ]]);
    $policies->publish($this->leave, '2026-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'leave', 'policy_id' => $this->leave->id, 'name' => 'Everyone', 'priority' => 10, 'conditions' => []]);
    app(EntityApplicability::class)->apply($this->leave, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin);

    $this->hireInto = fn (Company $company) => app(HireEmployeeAction::class)->handle(
        ['first_name' => fake()->firstName(), 'last_name' => 'Override'], ['joining_date' => '2026-01-01'], ['company_id' => $company->id],
    );
    $this->days = fn ($employee, ?string $on = null) => collect(app(LeaveEntitlements::class)->for($employee, $on))->map(fn ($e) => $e['days'])->all();
});

it('lets FynnEdge override annual and sick leave without changing Markedge (field-level, inherited vs customised)', function () {
    $mk = ($this->hireInto)($this->mk);
    $fe = ($this->hireInto)($this->fe);
    $rowsBefore = DB::table('policy_versions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    $this->overrides->set($this->leave, $this->fe->id, 'entitlements[leave_type_code=AL].days', 24, null, $this->admin, 'FynnEdge contract');
    $this->overrides->set($this->leave, $this->fe->id, 'entitlements[leave_type_code=SL].days', '8', null, $this->admin);

    expect(($this->days)($mk))->toBe(['AL' => 18, 'SL' => 6, 'CL' => 6])
        ->and(($this->days)($fe))->toBe(['AL' => 24, 'SL' => 8, 'CL' => 6])
        // The shared policy is untouched, byte for byte.
        ->and(DB::table('policy_versions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($rowsBefore);

    $explained = collect($this->overrides->explain($this->leave, $this->fe->id))->keyBy('path');
    expect($explained['entitlements[leave_type_code=AL].days'])->toMatchArray(['shared' => 18, 'override' => 24, 'overridden' => true, 'effective' => 24, 'source' => 'override'])
        ->and($explained['entitlements[leave_type_code=CL].days'])->toMatchArray(['shared' => 6, 'overridden' => false, 'effective' => 6, 'source' => 'shared'])
        ->and($explained['count_weekly_offs'])->toMatchArray(['shared' => null, 'overridden' => false, 'source' => 'default']);

    $version = app(PolicyResolver::class)->resolve('leave', $fe);
    expect($version->overriddenForCompany)->toBe($this->fe->id)
        ->and($version->overriddenPaths)->toBe(['entitlements[leave_type_code=AL].days', 'entitlements[leave_type_code=SL].days'])
        ->and(fn () => $version->save())->toThrow(ConfigurationException::class);

    $event = AuditEvent::query()->where('action', AuditAction::OverrideSet->value)->orderBy('id')->first();
    expect($event->reason)->toBe('FynnEdge contract')
        ->and($event->metadata['company_id'])->toBe($this->fe->id)
        ->and($event->fieldChanges()->sole()->only(['before', 'after']))->toBe(['before' => '18', 'after' => '24']);
});

it('keeps an override when the shared value changes, and restores the shared value when the override ends (precedence)', function () {
    $mk = ($this->hireInto)($this->mk);
    $fe = ($this->hireInto)($this->fe);
    $this->overrides->set($this->leave, $this->fe->id, 'entitlements[leave_type_code=AL].days', 24, null, $this->admin);

    // The shared policy moves to 20 from 1 December: Markedge follows, FynnEdge keeps its own value (never silently replaced).
    $policies = app(Policies::class);
    $policies->draft($this->leave, ['entitlements' => [['leave_type_code' => 'AL', 'days' => 20], ['leave_type_code' => 'SL', 'days' => 6], ['leave_type_code' => 'CL', 'days' => 6]]]);
    $policies->publish($this->leave, '2026-12-01');
    expect(($this->days)($mk, '2026-12-05')['AL'])->toBe(20)
        ->and(($this->days)($fe, '2026-12-05')['AL'])->toBe(24);

    // Ending it from 1 January restores the shared value from then; before, the override still applied.
    $this->overrides->end($this->leave, $this->fe->id, 'entitlements[leave_type_code=AL].days', '2027-01-01', $this->admin, 'Harmonised');
    expect(($this->days)($fe, '2026-12-31')['AL'])->toBe(24)
        ->and(($this->days)($fe, '2027-01-02')['AL'])->toBe(20)
        ->and(ConfigurationOverride::query()->sole()->only(['status']))->toBe(['status' => ConfigurationOverride::ENDED])
        ->and(ConfigurationOverride::query()->sole()->effective_to->toDateString())->toBe('2026-12-31')
        ->and(AuditEvent::query()->where('action', AuditAction::OverrideEnded->value)->count())->toBe(1);
});

it('versions overrides per field and company, effective-dated, without touching other companies', function () {
    $fe = ($this->hireInto)($this->fe);
    $this->overrides->set($this->leave, $this->fe->id, 'entitlements[leave_type_code=AL].days', 22, null, $this->admin);
    $this->overrides->set($this->leave, $this->fe->id, 'entitlements[leave_type_code=AL].days', 24, '2027-01-01', $this->admin);
    // A later choice for the same start supersedes the planned one before it ever applies.
    $this->overrides->set($this->leave, $this->fe->id, 'entitlements[leave_type_code=AL].days', 25, '2027-01-01', $this->admin);

    $rows = ConfigurationOverride::query()->orderBy('version')->get();
    expect($rows->map(fn ($r) => [$r->version, $r->value, $r->status, $r->effective_from->toDateString(), $r->effective_to?->toDateString()])->all())->toBe([
        [1, 22, 'ended', '2026-11-23', '2026-12-31'],
        [2, 24, 'superseded', '2027-01-01', null],
        [3, 25, 'active', '2027-01-01', null],
    ])
        ->and(($this->days)($fe)['AL'])->toBe(22)
        ->and(($this->days)($fe, '2027-02-01')['AL'])->toBe(25)
        ->and($this->overrides->valuesFor('policy', $this->leave->id, $this->mk->id))->toBe([])
        ->and($this->overrides->history($this->leave, $this->fe->id))->toHaveCount(3);
});

it('refuses overrides in the past, for companies the record does not apply to, for unknown fields and invalid values', function () {
    $refused = fn (callable $call) => rescue($call, fn (Throwable $e) => $e->getMessage(), false);

    expect($refused(fn () => $this->overrides->set($this->leave, $this->fe->id, 'entitlements[leave_type_code=AL].days', 24, '2026-11-01', $this->admin)))
        ->toContain('cannot start in the past')
        ->and($refused(fn () => $this->overrides->set($this->leave, $this->th->id, 'entitlements[leave_type_code=AL].days', 24, null, $this->admin)))
        ->toContain('does not apply to Third Labs')
        ->and($refused(fn () => $this->overrides->set($this->leave, $this->fe->id, 'entitlements[leave_type_code=ML].days', 24, null, $this->admin)))
        ->toContain('is not a field a company can override')
        ->and($refused(fn () => $this->overrides->set($this->leave, $this->fe->id, 'entitlements[leave_type_code=AL].days', 'many', null, $this->admin)))
        ->toContain('must be a number')
        ->and(ConfigurationOverride::query()->count())->toBe(0);
});

it('never lets an override start inside a closed payroll period of the company (historical integrity)', function () {
    PayrollPeriod::create(['company_id' => $this->fe->id, 'year' => 2026, 'month' => 11, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'closed']);

    expect(fn () => $this->overrides->set($this->leave, $this->fe->id, 'entitlements[leave_type_code=AL].days', 24, null, $this->admin))
        ->toThrow(ApplicabilityRefusedException::class, 'has closed the payroll period 1 Nov 2026 – 30 Nov 2026 already');
    $this->overrides->set($this->leave, $this->fe->id, 'entitlements[leave_type_code=AL].days', 24, '2026-12-01', $this->admin);
    // Markedge's open period is not affected.
    $this->overrides->set($this->leave, $this->mk->id, 'entitlements[leave_type_code=AL].days', 19, null, $this->admin);
    expect(ConfigurationOverride::query()->count())->toBe(2);
});

it('overrides a shared attendance grace period for FynnEdge only, and a shift\'s own grace through the shift resolver', function () {
    $policies = app(Policies::class);
    $attendance = Policy::create(['type' => 'attendance', 'name' => 'Group attendance', 'code' => 'GRP_ATT']);
    $policies->draft($attendance, ['grace_minutes' => 10]);
    $policies->publish($attendance, '2026-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'attendance', 'policy_id' => $attendance->id, 'name' => 'Everyone', 'priority' => 10, 'conditions' => []]);
    $mk = ($this->hireInto)($this->mk);
    $fe = ($this->hireInto)($this->fe);

    $this->overrides->set($attendance, $this->fe->id, 'grace_minutes', 20, null, $this->admin);
    $resolver = app(PolicyResolver::class);
    expect($resolver->resolve('attendance', $mk)->setting('grace_minutes'))->toBe(10)
        ->and($resolver->resolve('attendance', $fe)->setting('grace_minutes'))->toBe(20)
        ->and(collect($resolver->resolveAll($fe))->get('attendance')->setting('grace_minutes'))->toBe(20);

    $shift = Shift::create(['name' => 'General', 'code' => 'GEN', 'type' => 'fixed', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'break_minutes' => 60, 'full_day_minutes' => 480, 'half_day_minutes' => 240, 'grace_in_minutes' => 5, 'grace_out_minutes' => 0]);
    $schedule = WorkSchedule::create(['name' => 'Week', 'code' => 'WK', 'effective_from' => '2026-01-05',
        'pattern' => [['mon' => $shift->id, 'tue' => $shift->id, 'wed' => $shift->id, 'thu' => $shift->id, 'fri' => $shift->id, 'sat' => null, 'sun' => null]]]);
    foreach ([$mk, $fe] as $employee) {
        WorkScheduleAssignment::create(['employee_id' => $employee->id, 'work_schedule_id' => $schedule->id, 'effective_from' => '2026-01-05']);
    }
    $this->overrides->set($shift, $this->fe->id, 'grace_in_minutes', 15, null, $this->admin);

    $monday = Carbon::parse('2026-11-30');
    $shifts = app(ShiftResolver::class);
    $batch = $shifts->resolveBetween([$mk, $fe], $monday, $monday);
    expect($shifts->resolve($mk, $monday)['shift']->grace_in_minutes)->toBe(5)
        ->and($shifts->resolve($fe, $monday)['shift']->grace_in_minutes)->toBe(15)
        ->and($batch[$fe->id]['2026-11-30']['shift']->grace_in_minutes)->toBe(15)
        ->and($batch[$mk->id]['2026-11-30']['shift']->grace_in_minutes)->toBe(5)
        ->and($shift->fresh()->grace_in_minutes)->toBe(5)
        ->and(fn () => $shifts->resolve($fe, $monday)['shift']->save())->toThrow(ConfigurationException::class);
});

it('reads and writes rows by key, never by position (settings paths)', function () {
    $settings = ['entitlements' => [['leave_type_code' => 'AL', 'days' => 18], ['leave_type_code' => 'SL', 'days' => 6]], 'grace_minutes' => 5];

    expect(SettingsPath::get($settings, 'entitlements[leave_type_code=SL].days'))->toBe(6)
        ->and(SettingsPath::set($settings, 'entitlements[leave_type_code=SL].days', 8)['entitlements'][1])->toBe(['leave_type_code' => 'SL', 'days' => 8])
        ->and(SettingsPath::set($settings, 'entitlements[leave_type_code=ML].days', 8))->toBe($settings)
        ->and(SettingsPath::set($settings, 'grace_minutes', 9)['grace_minutes'])->toBe(9)
        ->and(SettingsPath::has($settings, 'entitlements[leave_type_code=ML].days'))->toBeFalse()
        ->and(SettingsPath::valid('entitlements[leave_type_code=AL].days'))->toBeTrue()
        ->and(SettingsPath::valid('a..b'))->toBeFalse();
});
