<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Experience\Services\ActionCatalogue;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Filament\Resources\LeaveBalances\Pages\ListLeaveBalances;
use App\Filament\Support\LeaveCreditAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/LeaveTestHelpers.php';

/*
| "Credit leave" on Leave → Balances: HR adds days to any employee's balance for a leave type and leave year, also when
| the employee has no balance row yet (the row's own "Adjust" needs one). Same right (leave.adjust) and access scope as
| "Adjust"; the credit is a ledger adjustment, so the balance row appears and the change is audited.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();

    // The policy gives EL only: CL has no balance row for anyone.
    leavePolicy(['EL' => ['days' => 12, 'accrual_frequency' => 'annual']]);
    $this->el = LeaveType::query()->where('code', 'EL')->firstOrFail();
    $this->cl = LeaveType::query()->where('code', 'CL')->firstOrFail();
    $company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['company_id' => $company->id]);
    $this->mumbai = Location::factory()->create(['company_id' => $company->id]);
    $this->asha = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP1'], ['company_id' => $company->id, 'location_id' => $this->mumbai->id]);
    forceLifecycle($this->asha, LifecycleState::Active);
    $this->ravi = app(HireEmployeeAction::class)->handle(['first_name' => 'Ravi', 'last_name' => 'N'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP2'], ['company_id' => $company->id, 'location_id' => $this->delhi->id]);
    forceLifecycle($this->ravi, LifecycleState::Active);
    app(LeaveAccrual::class)->accrue($this->asha);
});

function creditBalance(int $employeeId, int $typeId, int $year = 2026): ?LeaveBalance
{
    return LeaveBalance::query()->where('employee_id', $employeeId)->where('leave_type_id', $typeId)->where('period_year', $year)->first();
}

it('credits leave to an employee with no balance row for that leave type, creating the balance', function () {
    expect(creditBalance($this->asha->id, $this->cl->id))->toBeNull();

    Livewire::test(ListLeaveBalances::class)->assertActionVisible(LeaveCreditAction::NAME)
        ->callAction(LeaveCreditAction::NAME, data: ['employee_id' => $this->asha->id, 'leave_type_id' => $this->cl->id, 'period_year' => 2026, 'days' => 2.5, 'note' => 'Joining bonus leave'])
        ->assertHasNoActionErrors()
        ->assertNotified('Leave credited');

    $balance = creditBalance($this->asha->id, $this->cl->id);
    expect($balance)->not->toBeNull()
        ->and((float) $balance->adjusted)->toBe(2.5)
        ->and($balance->available())->toBe(2.5);

    $entry = LeaveLedgerEntry::query()->where('employee_id', $this->asha->id)->where('leave_type_id', $this->cl->id)->sole();
    expect($entry->type)->toBe('adjustment')->and((float) $entry->days)->toBe(2.5)->and($entry->note)->toBe('Joining bonus leave')
        ->and($entry->created_by)->toBe($this->hr->id);

    expect(AuditEvent::withoutTenancy()->where('action', AuditAction::LeaveBalanceAdjusted->value)->where('reason', 'Joining bonus leave')->exists())->toBeTrue();
});

it('adds to an existing balance and can credit the previous or next leave year', function () {
    expect(creditBalance($this->asha->id, $this->el->id)->available())->toBe(12.0);

    Livewire::test(ListLeaveBalances::class)
        ->callAction(LeaveCreditAction::NAME, data: ['employee_id' => $this->asha->id, 'leave_type_id' => $this->el->id, 'period_year' => 2026, 'days' => 1, 'note' => 'Worked on a holiday'])
        ->assertHasNoActionErrors()
        ->callAction(LeaveCreditAction::NAME, data: ['employee_id' => $this->asha->id, 'leave_type_id' => $this->el->id, 'period_year' => 2025, 'days' => 3, 'note' => 'Correction for last year'])
        ->assertHasNoActionErrors();

    expect(creditBalance($this->asha->id, $this->el->id)->available())->toBe(13.0)
        ->and(creditBalance($this->asha->id, $this->el->id, 2025)->available())->toBe(3.0)
        ->and(array_keys(LeaveCreditAction::years()))->toBe([2025, 2026, 2027]);
});

it('refuses zero, negative, non-half days, a missing reason and a year out of range, posting nothing', function () {
    $base = ['employee_id' => $this->asha->id, 'leave_type_id' => $this->cl->id, 'period_year' => 2026, 'note' => 'Bonus'];
    $refused = fn (array $data, string $field) => Livewire::test(ListLeaveBalances::class)->callAction(LeaveCreditAction::NAME, data: $data + $base)->assertHasActionErrors([$field]);

    $refused(['days' => 0], 'days');
    $refused(['days' => -2], 'days');
    $refused(['days' => 1.3], 'days');
    $refused(['days' => 1, 'note' => ''], 'note');
    $refused(['days' => 1, 'period_year' => 2030], 'period_year');

    expect(LeaveLedgerEntry::query()->where('leave_type_id', $this->cl->id)->exists())->toBeFalse();
    expect(fn () => LeaveCreditAction::credit($this->hr, ['days' => 1, 'period_year' => 2030] + $base))->toThrow(RuntimeException::class, 'leave year');
});

it('shows the button to holders of leave.adjust or leave.manage only', function () {
    $as = function (array $permissions) {
        $user = tenantUser($this->tenant, $permissions);
        $this->actingAs($user);

        return $user;
    };

    $as(['leave.view']);
    Livewire::test(ListLeaveBalances::class)->assertActionHidden(LeaveCreditAction::NAME);
    expect(fn () => LeaveCreditAction::credit(auth()->user(), ['employee_id' => $this->asha->id, 'leave_type_id' => $this->cl->id, 'period_year' => 2026, 'days' => 1, 'note' => 'x']))
        ->toThrow(RuntimeException::class, 'cannot change leave balances');

    $as(['leave.view', 'leave.adjust']);
    Livewire::test(ListLeaveBalances::class)->assertActionVisible(LeaveCreditAction::NAME);

    $as(['leave.view', 'leave.manage']);
    Livewire::test(ListLeaveBalances::class)->assertActionVisible(LeaveCreditAction::NAME);

    expect(LeaveLedgerEntry::query()->where('leave_type_id', $this->cl->id)->exists())->toBeFalse();
});

it('keeps a scoped HR user to the employees in their leave access', function () {
    $delhiHr = tenantUser($this->tenant, ['leave.view', 'leave.adjust', 'employee.view']);
    app(AccessScopes::class)->assign($delhiHr, ['location' => [$this->delhi->id]]);
    $this->actingAs($delhiHr->fresh());

    // The picker only offers employees in scope; the service refuses one out of scope all the same (defence in depth).
    $outOfScope = ['employee_id' => $this->asha->id, 'leave_type_id' => $this->cl->id, 'period_year' => 2026, 'days' => 1, 'note' => 'Out of scope'];
    Livewire::test(ListLeaveBalances::class)->callAction(LeaveCreditAction::NAME, data: $outOfScope)->assertHasActionErrors(['employee_id']);
    expect(fn () => LeaveCreditAction::credit(auth()->user(), $outOfScope))->toThrow(RuntimeException::class, 'access scope');
    expect(LeaveLedgerEntry::query()->withoutGlobalScopes()->where('employee_id', $this->asha->id)->where('leave_type_id', $this->cl->id)->exists())->toBeFalse();

    Livewire::test(ListLeaveBalances::class)
        ->callAction(LeaveCreditAction::NAME, data: ['employee_id' => $this->ravi->id, 'leave_type_id' => $this->cl->id, 'period_year' => 2026, 'days' => 1, 'note' => 'In scope'])
        ->assertHasNoActionErrors()->assertNotified('Leave credited');
    expect(creditBalance($this->ravi->id, $this->cl->id)->available())->toBe(1.0);
});

it('tells HR the current balance and a missing policy without creating a balance', function () {
    expect(LeaveCreditAction::balanceNote($this->asha->id, $this->cl->id, 2026))
        ->toContain('No Casual')->toContain('this credit starts it')->toContain('No leave policy gives this employee')
        ->and(creditBalance($this->asha->id, $this->cl->id))->toBeNull();

    expect(LeaveCreditAction::balanceNote($this->asha->id, $this->el->id, 2026))->toContain('12 days available now')->not->toContain('No leave policy')
        ->and(LeaveCreditAction::balanceNote(null, $this->el->id, 2026))->toBeNull();
});

it('is offered in the Actions panel, opening the button on the balances page', function () {
    $action = collect(app(ActionCatalogue::class)->for($this->hr))->firstWhere('key', 'credit_leave');

    expect($action)->not->toBeNull()->and($action['url'])->toContain('action='.LeaveCreditAction::NAME);

    $viewer = tenantUser($this->tenant, ['leave.view', 'employee.view']);
    $this->actingAs($viewer);
    expect(collect(app(ActionCatalogue::class)->for($viewer))->firstWhere('key', 'credit_leave'))->toBeNull();
});
