<?php

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveYear;
use App\Domain\Platform\Services\SettingsRepository;

require_once __DIR__.'/LeaveTestHelpers.php';

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->accrual = app(LeaveAccrual::class);
    $this->balances = app(LeaveBalances::class);
    $this->el = LeaveType::query()->where('code', 'EL')->first();
    $this->cl = LeaveType::query()->where('code', 'CL')->first();
    $this->sl = LeaveType::query()->where('code', 'SL')->first();
});

it('seeds the default leave types for a new tenant', function () {
    expect(LeaveType::query()->pluck('code')->all())->toContain('EL', 'CL', 'SL', 'ML', 'PL', 'CO', 'RH', 'LWP')
        ->and(LeaveType::query()->where('code', 'ML')->value('applicable_gender'))->toBe('female');
});

it('credits annual and periodic accruals idempotently, pro-rating the joining year', function () {
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'monthly'], 'CL' => ['days' => 12, 'accrual_frequency' => 'annual', 'prorate_on_join' => true], 'SL' => ['days' => 8, 'accrual_frequency' => 'quarterly']]);
    $employee = Employee::factory()->create(['joining_date' => '2026-04-10']);

    expect($this->accrual->accrue($employee, '2026-09-26'))->toBe(6 + 1 + 2);

    $year = app(LeaveYear::class)->periodFor('2026-09-26');
    expect((float) $this->balances->balance($employee, $this->el, $year)->accrued)->toBe(12.0)
        ->and((float) $this->balances->balance($employee, $this->cl, $year)->accrued)->toBe(9.0)
        ->and((float) $this->balances->balance($employee, $this->sl, $year)->accrued)->toBe(4.0);

    expect($this->accrual->accrue($employee, '2026-09-26'))->toBe(0)
        ->and($this->accrual->accrue($employee, '2026-10-01'))->toBe(2)
        ->and(LeaveLedgerEntry::query()->where('type', 'accrual')->count())->toBe(11);

    $future = Employee::factory()->create(['joining_date' => '2027-01-01']);
    expect($this->accrual->accrue($future, '2026-09-26'))->toBe(0);
});

it('carries forward up to the limit and lapses the rest at year end', function () {
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual', 'carry_forward_limit' => 10], 'CL' => ['days' => 12, 'accrual_frequency' => 'annual', 'carry_forward_limit' => 0]]);
    $employee = Employee::factory()->create(['joining_date' => '2025-01-01']);
    $this->accrual->accrue($employee, '2025-06-01');

    expect($this->accrual->closeYear($employee, 2025))->toBe(3)
        ->and($this->accrual->closeYear($employee, 2025))->toBe(0);

    expect((float) $this->balances->balance($employee, $this->el, 2025)->lapsed)->toBe(14.0)
        ->and((float) $this->balances->balance($employee, $this->el, 2025)->closing)->toBe(10.0)
        ->and((float) $this->balances->balance($employee, $this->el, 2026)->opening)->toBe(10.0)
        ->and((float) $this->balances->balance($employee, $this->cl, 2025)->lapsed)->toBe(12.0)
        ->and((float) $this->balances->balance($employee, $this->cl, 2026)->opening)->toBe(0.0);
});

it('respects a tenant leave year that starts in April', function () {
    app(SettingsRepository::class)->set('leave.year_start_month', 4);
    $years = app(LeaveYear::class);

    expect($years->periodFor('2026-03-31'))->toBe(2025)
        ->and($years->periodFor('2026-04-01'))->toBe(2026)
        ->and($years->start(2026)->toDateString())->toBe('2026-04-01')
        ->and($years->end(2026)->toDateString())->toBe('2027-03-31');

    leavePolicy(['EL' => ['days' => 12, 'accrual_frequency' => 'monthly']]);
    $employee = Employee::factory()->create(['joining_date' => '2026-04-01']);
    $this->accrual->accrue($employee, '2026-09-26');
    expect((float) $this->balances->balance($employee, $this->el, 2026)->accrued)->toBe(6.0);
});

it('runs through the command and auto-closes the previous year', function () {
    leavePolicy(['EL' => ['days' => 12, 'accrual_frequency' => 'annual', 'carry_forward_limit' => 5]]);
    $employee = Employee::factory()->create(['joining_date' => '2025-01-01']);
    $this->accrual->accrue($employee, '2025-03-01');

    $this->artisan('peopleos:leave:accrue', ['--date' => '2026-01-02'])->assertSuccessful();

    expect((float) $this->balances->balance($employee, $this->el, 2026)->opening)->toBe(5.0)
        ->and((float) $this->balances->balance($employee, $this->el, 2026)->accrued)->toBe(12.0)
        ->and((float) $this->balances->balance($employee, $this->el, 2025)->lapsed)->toBe(7.0);
});
