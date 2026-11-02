<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\BulkOperations\CarryForwardLeaveOperation;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveEntitlements;
use App\Domain\Leave\Services\LeaveYearEnd;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\LeaveCarryForward;
use App\Filament\Pages\TimeOfficeDesk;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/*
| HROne parity TX-8a (matrix 24 §24.6.6 #1–5, §24.1 #22): "Carry forward leaves" — the leave year end on screen. The preview
| is LeaveAccrual::yearEnd (the calculation the year close posts) read without writing; "Carry forward" posts
| LeaveAccrual::closeYear for the selected rows (idempotent, audited as the year close), above the WO-1 threshold as a bulk
| run; CSV download; "Pending" = a closing balance in a leave type a leave policy carries and no carry / lapse entry; the
| time office desk's reminder from `leave.carry_forward_alert_days` days before the year ends.
| Today is Sunday 10 Jan 2027: the 2026 leave year (calendar) has ended.
*/

beforeEach(function () {
    $this->travelTo('2027-01-10 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create(['name' => 'Acme India']);
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi', 'code' => 'DEL']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Mumbai', 'code' => 'MUM']);
    $this->el = LeaveType::query()->where('code', 'EL')->firstOrFail();
    $this->cl = LeaveType::query()->where('code', 'CL')->firstOrFail();
    $this->sl = LeaveType::query()->where('code', 'SL')->firstOrFail();
    $this->co = LeaveType::query()->where('code', 'CO')->firstOrFail();

    // Everyone: EL up to 10 days, CL none (all of it lapses), SL all of it.
    $this->standard = leavePolicy([
        'EL' => ['days' => 24, 'accrual_frequency' => 'annual', 'carry_forward_limit' => 10],
        'CL' => ['days' => 12, 'accrual_frequency' => 'annual', 'carry_forward_mode' => 'zero'],
        'SL' => ['days' => 8, 'accrual_frequency' => 'annual', 'carry_forward_mode' => 'all'],
    ]);
    // Mumbai: EL only, up to 5 days (a rule with a higher priority).
    $this->mumbaiPolicy = tx8Policy('MUM_LEAVE', 'Mumbai leave', ['EL' => ['days' => 18, 'accrual_frequency' => 'annual', 'carry_forward_limit' => 5]],
        [['field' => 'location_id', 'operator' => 'equals', 'value' => $this->mumbai->id]]);

    $this->asha = tx8Hire('Asha', $this->delhi, 'EMP1');
    $this->ravi = tx8Hire('Ravi', $this->mumbai, 'EMP2');
    $this->accrual = app(LeaveAccrual::class);
    $this->balances = app(LeaveBalances::class);
    $this->accrual->accrue($this->asha, '2026-06-01');
    $this->accrual->accrue($this->ravi, '2026-06-01');
    // Asha took 4 days of EL in 2026 and earned 2 days of comp-off (no leave policy carries comp-off).
    $this->balances->post($this->asha, $this->el, 2026, 'usage', -4, null, 'Used', null, '2026-07-01');
    $this->balances->post($this->asha, $this->co, 2026, 'comp_off_credit', 2, null, 'Worked a holiday', null, '2026-08-15');
    $this->years = app(LeaveYearEnd::class);
});

function tx8Hire(string $first, Location $location, string $code, string $joining = '2025-01-01'): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => $joining, 'employee_code' => $code],
        ['company_id' => $location->company_id, 'location_id' => $location->id]);
}

/** A leave policy for the employees matching $conditions, ahead of the "everyone" policy. */
function tx8Policy(string $code, string $name, array $entitlements, array $conditions): Policy
{
    $rows = [];
    foreach ($entitlements as $typeCode => $settings) {
        $rows[] = ['leave_type_code' => $typeCode] + $settings + LeaveEntitlements::DEFAULTS;
    }
    $policy = Policy::create(['type' => 'leave', 'name' => $name, 'code' => $code]);
    app(Policies::class)->draft($policy, ['count_weekly_offs' => false, 'count_holidays' => false, 'entitlements' => $rows]);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'leave', 'policy_id' => $policy->id, 'name' => $name, 'priority' => 10, 'conditions' => $conditions]);

    return $policy;
}

/** @return array<string, array{0: float, 1: float, 2: float, 3: string}> code => [closing, carry, lapse, status] */
function tx8Lines(Employee $employee, int $period = 2026, ?array $codes = null): array
{
    $row = app(LeaveYearEnd::class)->preview([$employee->fresh('person')], $period, $codes)[$employee->id] ?? ['lines' => []];

    return collect($row['lines'])->mapWithKeys(fn (array $l) => [$l['code'] => [$l['closing'], $l['carry'], $l['lapse'], $l['status']]])->sortKeys()->all();
}

/** @return array<string, array{0: float, 1: float}> code => [carried, lapsed] as posted */
function tx8Posted(Employee $employee, int $period = 2026): array
{
    $out = [];
    foreach (LeaveLedgerEntry::query()->where('employee_id', $employee->id)->whereIn('accrual_key', ["cf-{$period}", "lapse-{$period}"])->with('leaveType')->get() as $entry) {
        $code = $entry->leaveType->code;
        $out[$code] ??= [0.0, 0.0];
        $entry->accrual_key === "cf-{$period}" ? $out[$code][0] += (float) $entry->days : $out[$code][1] -= (float) $entry->days;
    }
    ksort($out);

    return $out;
}

// ---- The calculation: one for the preview and the posting -----------------------------------------------------------

it('previews per employee and leave type exactly what the year close posts, writing nothing', function () {
    $ledger = LeaveLedgerEntry::query()->count();
    $audits = AuditEvent::query()->withoutGlobalScopes()->count();
    $this->travel(5)->minutes();
    $computed = LeaveBalance::query()->max('computed_at');

    $preview = tx8Lines($this->asha);
    expect($preview)->toBe([
        'CL' => [12.0, 0.0, 12.0, 'pending'],
        'EL' => [20.0, 10.0, 10.0, 'pending'],
        'SL' => [8.0, 8.0, 0.0, 'pending'],
    ])
        // Ravi's own policy (Mumbai) carries EL only, up to 5 days.
        ->and(tx8Lines($this->ravi))->toBe(['EL' => [18.0, 5.0, 13.0, 'pending']])
        ->and(app(LeaveYearEnd::class)->preview([$this->asha->fresh('person')], 2026)[$this->asha->id]['policy'])->toBe('Standard leave')
        // Nothing was written: no ledger entry, no balance recomputed, no audit event.
        ->and(LeaveLedgerEntry::query()->count())->toBe($ledger)
        ->and(LeaveBalance::query()->max('computed_at'))->toBe($computed)
        ->and(AuditEvent::query()->withoutGlobalScopes()->count())->toBe($audits);

    // Posting writes exactly the preview, and the preview then shows it as carried forward.
    $result = $this->years->post([$this->asha], 2026, null, $this->hr);
    expect($result['written'])->toBe(4)
        ->and($result['done'][0]['message'])->toContain('EL: 10 carried forward, 10 lapsed')->toContain('CL: 0 carried forward, 12 lapsed')->toContain('SL: 8 carried forward, 0 lapsed')
        ->and(tx8Posted($this->asha))->toBe(collect($preview)->map(fn (array $l) => [$l[1], $l[2]])->all())
        ->and(tx8Lines($this->asha))->toBe(collect($preview)->map(fn (array $l) => [$l[0], $l[1], $l[2], 'posted'])->all())
        ->and((float) $this->balances->balance($this->asha, $this->el, 2027)->opening)->toBe(10.0)
        ->and((float) $this->balances->balance($this->asha, $this->el, 2026)->lapsed)->toBe(10.0);

    // Audited as the year close: one LEAVE_CARRIED_FORWARD per carry, one LEAVE_EXPIRED (lapse) per lapse, in one operation.
    $carried = AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::LeaveCarriedForward->value)->get();
    $lapsed = AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::LeaveExpired->value)->get();
    expect($carried)->toHaveCount(2)->and($lapsed)->toHaveCount(2)
        ->and($carried->merge($lapsed)->pluck('operation_id')->unique())->toHaveCount(1)
        ->and($carried->first()->reason)->toBe('Year-end 2026');
});

it('posts only the chosen rows, and a second post is a no-op', function () {
    // Only EL for Asha: CL and SL stay to carry forward; Ravi is untouched.
    $this->years->post([$this->asha], 2026, ['EL'], $this->hr);
    expect(tx8Posted($this->asha))->toBe(['EL' => [10.0, 10.0]])
        ->and(tx8Posted($this->ravi))->toBe([])
        ->and(tx8Lines($this->asha)['CL'][3])->toBe('pending')->and(tx8Lines($this->asha)['EL'][3])->toBe('posted')
        ->and($this->years->pendingCount(2026))->toBe(2);

    // From the page: the selected row only.
    Livewire::test(LeaveCarryForward::class)->assertOk()->assertSet('year', '2026')->assertCanSeeTableRecords([$this->asha, $this->ravi])
        ->callTableBulkAction('carryForward', [$this->asha])->assertHasNoTableBulkActionErrors()
        ->assertSet('result.written', 2)->assertNotified('Leave carried forward successfully.');
    expect(tx8Posted($this->asha))->toBe(['CL' => [0.0, 12.0], 'EL' => [10.0, 10.0], 'SL' => [8.0, 0.0]])
        ->and(tx8Posted($this->ravi))->toBe([])
        ->and($this->years->pendingCount(2026))->toBe(1);

    // Again: nothing new is written, nothing new is audited.
    $ledger = LeaveLedgerEntry::query()->count();
    $audits = AuditEvent::query()->withoutGlobalScopes()->whereIn('action', [AuditAction::LeaveCarriedForward->value, AuditAction::LeaveExpired->value])->count();
    Livewire::test(LeaveCarryForward::class)->callTableBulkAction('carryForward', [$this->asha])->assertHasNoTableBulkActionErrors()
        ->assertSet('result.written', 0)->assertSet('result.skipped.0.message', 'Already carried forward: nothing new posted.');
    expect(LeaveLedgerEntry::query()->count())->toBe($ledger)
        ->and(AuditEvent::query()->withoutGlobalScopes()->whereIn('action', [AuditAction::LeaveCarriedForward->value, AuditAction::LeaveExpired->value])->count())->toBe($audits)
        ->and($this->accrual->closeYear($this->asha, 2026))->toBe(0);
});

it('leaves the year close command as it was: the command and the screen post the same entries', function () {
    $bina = tx8Hire('Bina', $this->delhi, 'EMP3');
    $this->accrual->accrue($bina, '2026-06-01');
    $this->balances->post($bina, $this->el, 2026, 'usage', -4, null, 'Used', null, '2026-07-01');

    $this->years->post([$this->asha], 2026, null, $this->hr);
    $this->artisan('peopleos:leave:accrue', ['--close-year' => 2026, '--date' => '2027-01-10'])->assertSuccessful();

    $entries = fn (Employee $e) => LeaveLedgerEntry::query()->where('employee_id', $e->id)->whereIn('accrual_key', ['cf-2026', 'lapse-2026'])->with('leaveType')->orderBy('id')->get()
        ->map(fn (LeaveLedgerEntry $x) => [$x->leaveType->code, $x->type, $x->period_year, $x->entry_date->toDateString(), (float) $x->days, $x->accrual_key, $x->note])->all();
    expect($entries($bina))->toBe($entries($this->asha))->not->toBe([])
        // The command closed Ravi too (every employed employee), and Asha's posting stayed a single one.
        ->and(tx8Posted($this->ravi))->toBe(['EL' => [5.0, 13.0]])
        ->and(LeaveLedgerEntry::query()->where('employee_id', $this->asha->id)->whereIn('accrual_key', ['cf-2026', 'lapse-2026'])->count())->toBe(4);

    // closeYear without leave types closes every type of the policy (as before); with them, only those.
    $kiran = tx8Hire('Kiran', $this->delhi, 'EMP4');
    $this->accrual->accrue($kiran, '2026-06-01');
    expect($this->accrual->closeYear($kiran, 2026, ['SL']))->toBe(1)
        ->and($this->accrual->closeYear($kiran, 2026))->toBe(3)
        ->and($this->accrual->closeYear($kiran, 2026))->toBe(0);
});

// ---- The screen -------------------------------------------------------------------------------------------------------

it('narrows the preview by leave type, leave policy and what is still to do, and counts the pending employees', function () {
    expect($this->years->pendingCount(2026))->toBe(2)
        ->and(array_keys($this->years->types(2026)))->toEqualCanonicalizing(['EL', 'CL', 'SL']);

    // A balance in comp-off (no leave policy carries it) is neither listed nor pending.
    $page = Livewire::test(LeaveCarryForward::class)->assertOk()->assertSee('2 employees still to carry forward')
        ->assertDontSee('CO · ');

    $page->set('type', 'EL')->assertCanSeeTableRecords([$this->asha, $this->ravi])
        ->filterTable('policy', $this->mumbaiPolicy->id)->assertCanSeeTableRecords([$this->ravi])->assertCanNotSeeTableRecords([$this->asha]);

    $this->years->post([$this->ravi], 2026, null, $this->hr);
    Livewire::test(LeaveCarryForward::class)->filterTable('show', 'pending')->assertCanSeeTableRecords([$this->asha])->assertCanNotSeeTableRecords([$this->ravi])
        ->assertSee('1 employee still to carry forward');
    Livewire::test(LeaveCarryForward::class)->filterTable('show', 'posted')->assertCanSeeTableRecords([$this->ravi])->assertCanNotSeeTableRecords([$this->asha]);
    Livewire::withQueryParams(['view' => 'pending'])->test(LeaveCarryForward::class)->assertCanSeeTableRecords([$this->asha])->assertCanNotSeeTableRecords([$this->ravi]);
    Livewire::withQueryParams([]);

    // A balance in a type the employee's own policy does not list is shown, and the year close leaves it.
    $this->balances->post($this->ravi, $this->cl, 2026, 'adjustment', 3, null, 'Opening', null, '2026-01-01');
    expect(tx8Lines($this->ravi)['CL'])->toBe([3.0, 0.0, 0.0, 'not_in_policy'])
        ->and($this->years->post([$this->ravi], 2026, ['CL'], $this->hr)['skipped'][0]['message'])->toBe('Nothing to carry forward or lapse.');

    // Exited employees are not taken (the year close takes employed people only).
    expect(collect($this->years->population()->pluck('id'))->all())->toContain($this->asha->id, $this->ravi->id);
});

it('refuses to post a leave year that has not ended, while the preview shows the balances so far', function () {
    $this->travelTo('2026-11-15 10:00:00');
    Livewire::test(LeaveCarryForward::class)->assertOk()->assertSet('year', '2026')->assertCanSeeTableRecords([$this->asha])
        ->assertTableBulkActionDisabled('carryForward')->assertSee('The year ends on 31 Dec 2026');
    expect(fn () => $this->years->post([$this->asha], 2026, null, $this->hr))->toThrow(RuntimeException::class, 'The 2026 leave year ends on 31 Dec 2026: carry forward once it has ended.')
        ->and(fn () => app(BulkOperations::class)->get(CarryForwardLeaveOperation::KEY)->prepare(['period' => 2026], $this->hr))->toThrow(RuntimeException::class, 'carry forward once it has ended')
        ->and(tx8Lines($this->asha)['EL'])->toBe([20.0, 10.0, 10.0, 'pending'])
        ->and(tx8Posted($this->asha))->toBe([]);
});

it('runs large selections as a WO-1 bulk run under one audit operation, and needs bulk.run for them', function () {
    config(['peopleos.bulk.confirm_over' => 1]);
    Livewire::test(LeaveCarryForward::class)
        ->callTableBulkAction('carryForward', [$this->asha, $this->ravi], data: ['confirm_count' => '2'])->assertHasNoTableBulkActionErrors();

    $run = BulkRun::query()->latest('id')->firstOrFail();
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($run->operation)->toBe(CarryForwardLeaveOperation::KEY)->and($run->register)->toBe('leave_carry_forward')->and($run->status)->toBe('completed')
        ->and($run->input)->toBe(['period' => 2026, 'codes' => null])
        ->and($items[$this->asha->id]->outcome)->toBe('done')->and($items[$this->asha->id]->message)->toContain('EL: 10 carried forward, 10 lapsed')
        ->and($items[$this->ravi->id]->message)->toBe('EL: 5 carried forward, 13 lapsed')
        ->and(tx8Posted($this->ravi))->toBe(['EL' => [5.0, 13.0]])
        ->and(AuditEvent::query()->withoutGlobalScopes()->whereIn('action', [AuditAction::LeaveCarriedForward->value, AuditAction::LeaveExpired->value])->pluck('operation_id')->unique()->all())->toBe([$run->operation_id]);

    // Everyone's 2026 is carried forward, so the screen now opens on the current year.
    Livewire::test(LeaveCarryForward::class)->assertSet('year', '2027');

    // Without bulk.run a large selection does not start.
    $this->actingAs(tenantUser($this->tenant, ['leave.view', 'leave.carry_forward']));
    Livewire::test(LeaveCarryForward::class)->set('year', '2026')->assertCanSeeTableRecords([$this->asha, $this->ravi])->callTableBulkAction('carryForward', [$this->asha, $this->ravi], data: ['confirm_count' => '2'])->assertNotified('Not started');
    expect(BulkRun::query()->count())->toBe(1);
});

it('downloads the preview as CSV, audited as an export', function () {
    Livewire::test(LeaveCarryForward::class)->callAction('download')->assertFileDownloaded('carry-forward-leaves-2026.csv');
    Livewire::test(LeaveCarryForward::class)->set('type', 'EL')->callAction('download')->assertFileDownloaded('carry-forward-leaves-2026-el.csv');

    $csv = $this->years->csv($this->hr, Employee::query()->with('person')->whereKey([$this->asha->id, $this->ravi->id])->orderBy('employee_code')->get(), 2026);
    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
    expect($rows[0])->toBe(['Employee code', 'Employee name', 'Policy name', 'Leave year', 'Leave type', 'No. of total leave', 'No. of leaves to be lapsed', 'No. of leaves to be carried forward', 'Status'])
        ->and(count($rows))->toBe(5)
        ->and(collect($rows)->first(fn (array $r) => $r[0] === 'EMP1' && str_starts_with($r[4], 'EL ')))->toMatchArray([1 => 'Asha Test', 2 => 'Standard leave', 3 => '2026', 5 => '20', 6 => '10', 7 => '10', 8 => 'To carry forward'])
        ->and(collect($rows)->first(fn (array $r) => $r[0] === 'EMP2'))->toMatchArray([2 => 'Mumbai leave', 5 => '18', 6 => '13', 7 => '5'])
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::Export->value)->where('entity_label', 'Carry forward leaves (2026)')->count())->toBe(3);
});

// ---- Access ------------------------------------------------------------------------------------------------------------

it('opens for leave.view with leave.carry_forward (or leave.manage) only', function () {
    foreach ([[['leave.view'], false], [['leave.carry_forward'], false], [['leave.view', 'leave.carry_forward'], true], [['leave.view', 'leave.manage'], true]] as [$keys, $opens]) {
        $this->actingAs(tenantUser($this->tenant, $keys));
        expect(LeaveCarryForward::canAccess())->toBe($opens, implode('+', $keys));
        $opens ? $this->get(LeaveCarryForward::getUrl())->assertOk()->assertSee('Carry forward leaves') : $this->get(LeaveCarryForward::getUrl())->assertForbidden();
    }
    expect(app(BulkOperations::class)->get(CarryForwardLeaveOperation::KEY)->permission())->toBe('leave.carry_forward')
        ->and(config('peopleos.permission_splits')['leave.carry_forward'] ?? null)->toBe('leave.manage');
});

it('lists and posts only employees inside the viewer\'s access scope', function () {
    $scoped = tenantUser($this->tenant, ['leave.view', 'leave.carry_forward']);
    app(AccessScopes::class)->assign($scoped, ['location' => [$this->delhi->id]]);
    $this->actingAs($scoped);

    Livewire::test(LeaveCarryForward::class)->assertOk()->assertCanSeeTableRecords([$this->asha])->assertCanNotSeeTableRecords([$this->ravi])
        ->assertSee('1 employee still to carry forward');
    expect($this->years->pendingCount(2026))->toBe(1);
    $result = $this->years->post([$this->ravi, $this->asha], 2026, null, $scoped);
    expect($result['refused'])->toHaveCount(1)->and($result['refused'][0]['reason'])->toBe('Outside your access scope.')
        ->and(tx8Posted($this->ravi))->toBe([])->and(tx8Posted($this->asha))->not->toBe([]);
});

// ---- The desk, the reminder and placement --------------------------------------------------------------------------------

it('reminds HR on the time office desk from the set number of days before the year ends until it is carried forward', function () {
    $settings = app(SettingsRepository::class);
    // Off by default.
    expect($this->years->alert())->toBeNull()->and((new TimeOfficeDesk)->notices())->toBe([]);

    $settings->set('leave.carry_forward_alert_days', 30, 'test');
    // The year has ended with two employees to carry forward.
    expect($this->years->alert())->toMatchArray(['period' => 2026, 'ended' => true, 'pending' => 2]);
    $this->get(TimeOfficeDesk::getUrl())->assertOk()->assertSee('The 2026 leave year ended on 31 Dec 2026: leave still to carry forward')
        ->assertSee('2 employees have leave of that year still to carry forward or lapse.');

    // 21 days before the year ends: shown; 46 days before: not yet.
    $this->travelTo('2026-12-10 09:00:00');
    expect($this->years->alert())->toMatchArray(['period' => 2026, 'ended' => false, 'days_left' => 21, 'pending' => 2])
        ->and((new TimeOfficeDesk)->notices()[0]['title'])->toBe('The 2026 leave year ends on 31 Dec 2026 (21 days left)');
    $this->travelTo('2026-11-15 09:00:00');
    expect($this->years->alert())->toBeNull();

    // Carried forward: no more reminder.
    $this->travelTo('2027-01-10 10:00:00');
    $this->years->post([$this->asha, $this->ravi], 2026, null, $this->hr);
    expect($this->years->alert())->toBeNull()->and((new TimeOfficeDesk)->notices())->toBe([]);

    // People who cannot carry forward never see it.
    $this->balances->post($this->asha, $this->el, 2026, 'adjustment', 1, null, 'Late correction', null, '2026-12-31');
    $this->actingAs(tenantUser($this->tenant, ['leave.view', 'leave.adjust']));
    expect((new TimeOfficeDesk)->notices())->toBe([]);
});

it('sits on the time office desk, in the Time & leave area and the setup centre', function () {
    $entry = collect((new TimeOfficeDesk)->deskSections())->flatMap(fn (array $s) => $s['items'])->firstWhere('label', 'Carry forward leaves');
    expect($entry)->not->toBeNull()->and($entry['count'])->toBe(2)->and($entry['url'])->toBe(LeaveCarryForward::getUrl());

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $area = collect(app(ExperienceNavigation::class)->children($this->hr, 'people'))->firstWhere('label', 'Time & leave');
    expect(array_column($area['modules'] ?? [], 'key'))->toContain(LeaveCarryForward::class)
        ->and(collect(config('peopleos_setup.modules.leave.links'))->pluck('target')->all())->toContain(LeaveCarryForward::class)
        ->and(collect(config('peopleos_setup.modules.leave.settings'))->pluck('key')->all())->toContain('leave.carry_forward_alert_days')
        ->and(config('peopleos.settings')['leave.carry_forward_alert_days'])->toBe(0);
});

it('keeps the TX-8a leave code free of payroll words', function () {
    foreach (['Domain/Leave/Services/LeaveYearEnd.php', 'Domain/Leave/Services/LeaveAccrual.php', 'Domain/Experience/BulkOperations/CarryForwardLeaveOperation.php', 'Filament/Pages/LeaveCarryForward.php'] as $file) {
        expect((bool) preg_match('/\b(payroll|salary|wage|wages|deduction|deductions|earning|earnings|ctc|payslip)\b/i', file_get_contents(app_path($file))))->toBeFalse("{$file} must not use payroll words");
    }
});
