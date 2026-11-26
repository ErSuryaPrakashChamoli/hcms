<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Filament\Resources\Policies\Pages\ListPolicies;
use Livewire\Livewire;

/*
| Back-dated company values (owner, 2026-11-26: "do the same for company values too"), the rule of "Applies to…": a
| company's own value may start in the past only within the payroll months still open for that company (the previous
| payroll month at most, never a finalized or paid payroll or a closed attendance month), never before the last change of
| that field for the company (a value that applied is never replaced as if it never had), and only with a reason.
| Today is 23 Nov 2026; months are calendar months.
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
    foreach (['AL' => 'Annual leave', 'SL' => 'Sick leave'] as $code => $name) {
        LeaveType::query()->firstOrCreate(['code' => $code], ['name' => $name]);
    }
    $policies = app(Policies::class);
    $this->leave = Policy::create(['type' => 'leave', 'name' => 'Group leave', 'code' => 'GRP_LEAVE']);
    $policies->draft($this->leave, ['entitlements' => [['leave_type_code' => 'AL', 'days' => 18], ['leave_type_code' => 'SL', 'days' => 6]]]);
    $policies->publish($this->leave, '2026-01-01');
    app(EntityApplicability::class)->apply($this->leave, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin);

    $this->al = 'entitlements[leave_type_code=AL].days';
    $this->sl = 'entitlements[leave_type_code=SL].days';
    $this->message = fn (callable $call) => rescue($call, fn (Throwable $e) => $e->getMessage(), false);
});

it('back-dates a company value within the open payroll months, with a reason, and audits the day it starts', function () {
    expect(($this->message)(fn () => $this->overrides->set($this->leave, $this->fe->id, $this->al, 24, '2026-11-01', $this->admin)))->toBe('A back-dated change needs a reason.')
        ->and(($this->message)(fn () => $this->overrides->set($this->leave, $this->fe->id, $this->al, 24, '2026-09-30', $this->admin, 'Too early')))
        ->toBe('It can start on 1 Oct 2026 at the earliest: a change can reach back to the start of the previous payroll month (Oct 2026) at most. Choose that day or a later one.')
        ->and(ConfigurationOverride::query()->count())->toBe(0);

    $this->overrides->set($this->leave, $this->fe->id, $this->al, 24, '2026-11-01', $this->admin, 'FynnEdge agreement from 1 Nov');

    expect($this->overrides->valuesFor('policy', $this->leave->id, $this->fe->id, '2026-11-05'))->toBe([$this->al => 24])
        ->and($this->overrides->valuesFor('policy', $this->leave->id, $this->fe->id, '2026-10-31'))->toBe([])
        ->and($this->overrides->valuesFor('policy', $this->leave->id, $this->mk->id, '2026-11-05'))->toBe([]);
    $event = AuditEvent::query()->where('action', AuditAction::OverrideSet->value)->sole();
    expect($event->effective_date->toDateString())->toBe('2026-11-01')
        ->and($event->reason)->toBe('FynnEdge agreement from 1 Nov')
        ->and(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue();
});

it('never reaches a finalized payroll month of the company; other companies are not limited by it', function () {
    PayrollPeriod::query()->create(['company_id' => $this->fe->id, 'year' => 2026, 'month' => 10, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        'payment_date' => '2026-10-31', 'status' => 'closed']);

    expect(($this->message)(fn () => $this->overrides->set($this->leave, $this->fe->id, $this->al, 24, '2026-10-15', $this->admin, 'October too')))
        ->toBe('It can start on 1 Nov 2026 at the earliest: payroll for Oct 2026 is finalized for FynnEdge Advisory. Choose that day or a later one.')
        ->and($this->overrides->earliestStart($this->leave, $this->fe->id, [$this->al])['date'])->toBe('2026-11-01')
        ->and($this->overrides->earliestStart($this->leave, $this->mk->id, [$this->al])['date'])->toBe('2026-10-01');

    $this->overrides->set($this->leave, $this->mk->id, $this->al, 20, '2026-10-15', $this->admin, 'Markedge October is still open');
    expect($this->overrides->valuesFor('policy', $this->leave->id, $this->mk->id, '2026-10-15'))->toBe([$this->al => 20]);
});

it('keeps each field in order: never before its last change for the company, so a value that applied is never replaced', function () {
    $this->travelTo('2026-11-20 09:00:00');
    $first = $this->overrides->set($this->leave, $this->fe->id, $this->al, 24, null, $this->admin, 'From today');
    $this->travelTo('2026-11-23 10:00:00');

    expect(($this->message)(fn () => $this->overrides->set($this->leave, $this->fe->id, $this->al, 22, '2026-11-10', $this->admin, 'Earlier')))
        ->toContain('It can start on 20 Nov 2026 at the earliest:')->toContain('last changed on 20 Nov 2026')
        ->and($this->overrides->earliestStart($this->leave, $this->fe->id, [$this->al])['date'])->toBe('2026-11-20')
        // Another field of the same company has its own history.
        ->and($this->overrides->earliestStart($this->leave, $this->fe->id, [$this->sl])['date'])->toBe('2026-10-01')
        ->and($first->refresh()->status)->toBe(ConfigurationOverride::ACTIVE);

    $this->overrides->set($this->leave, $this->fe->id, $this->sl, 8, '2026-11-10', $this->admin, 'Sick leave from 10 Nov');
    expect($this->overrides->valuesFor('policy', $this->leave->id, $this->fe->id, '2026-11-21'))->toEqual([$this->al => 24, $this->sl => 8]);
});

it('ends a company value back-dated, within the same limits', function () {
    $this->overrides->set($this->leave, $this->fe->id, $this->al, 24, '2026-11-01', $this->admin, 'From 1 Nov');

    expect(($this->message)(fn () => $this->overrides->end($this->leave, $this->fe->id, $this->al, '2026-10-20', $this->admin, 'Before it started')))
        ->toContain('It can start on 1 Nov 2026 at the earliest:')
        ->and(($this->message)(fn () => $this->overrides->end($this->leave, $this->fe->id, $this->al, '2026-11-15', $this->admin)))->toBe('A back-dated change needs a reason.');

    expect($this->overrides->end($this->leave, $this->fe->id, $this->al, '2026-11-15', $this->admin, 'Shared again from 15 Nov'))->toBeTrue()
        ->and($this->overrides->valuesFor('policy', $this->leave->id, $this->fe->id, '2026-11-14'))->toBe([$this->al => 24])
        ->and($this->overrides->valuesFor('policy', $this->leave->id, $this->fe->id, '2026-11-15'))->toBe([]);
});

it('offers earlier days on the Company values… screen, with a tick and a reason', function () {
    $policies = app(Policies::class);
    $attendance = Policy::create(['type' => 'attendance', 'name' => 'Group attendance', 'code' => 'GRP_ATT']);
    $policies->draft($attendance, ['grace_minutes' => 10]);
    $policies->publish($attendance, '2026-01-01');
    $data = ['company_id' => $this->fe->id, 'operation' => 'set', 'field' => 'grace_minutes', 'value_number' => 20];

    Livewire::test(ListPolicies::class)->callTableAction('companyValues', $attendance, $data + ['effective_from' => '2026-09-30', 'backdated_confirmed' => true, 'audit_reason' => 'Too early'])
        ->assertHasTableActionErrors(['effective_from']);
    Livewire::test(ListPolicies::class)->callTableAction('companyValues', $attendance, $data + ['effective_from' => '2026-11-01'])
        ->assertHasTableActionErrors(['backdated_confirmed', 'audit_reason']);
    expect(ConfigurationOverride::query()->where('field', 'grace_minutes')->count())->toBe(0);

    Livewire::test(ListPolicies::class)->callTableAction('companyValues', $attendance, $data + ['effective_from' => '2026-11-01', 'backdated_confirmed' => true, 'audit_reason' => 'Grace from 1 Nov'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Company value saved');
    expect(ConfigurationOverride::query()->where('field', 'grace_minutes')->sole()->effective_from->toDateString())->toBe('2026-11-01');
});
