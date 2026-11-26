<?php

use App\Domain\Attendance\Models\AttendanceMonthClose;
use App\Domain\Attendance\Services\AttendanceMonthCloses;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Models\ConfigurationApplicabilityCompany;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Departments\Pages\ListDepartments;

/*
| Back-dated applicability (owner, 2026-11-26: "by default today's date, but the calendar can pick a previous date … don't
| keep it open for any back dated days, it must meet the compliances"). A change to where a record applies may start in
| the past only as far as the payroll months still open for the companies it touches: at most the start of the previous
| payroll month, never into a month whose payroll is finalized or whose attendance is closed, never before the record's
| last applicability change; a back-dated change needs a reason and is audited as back-dated.
| Today is 23 Nov 2026; months are calendar months (no pay cycle set).
*/

beforeEach(function () {
    $this->travelTo('2026-11-23 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->applicability = app(EntityApplicability::class);

    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->th = Company::factory()->create(['name' => 'Third Labs', 'code' => 'TH']);
    $this->engineer = Designation::factory()->create(['name' => 'Engineer', 'code' => 'ENG']);
    $this->finalizeOctober = fn (Company $company) => PayrollPeriod::query()->create(['company_id' => $company->id, 'year' => 2026, 'month' => 10,
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'payment_date' => '2026-10-31', 'status' => 'closed']);
});

it('back-dates within the open payroll months, to the start of the previous payroll month at most', function () {
    $within = $this->applicability->plan($this->engineer, ApplicabilitySelection::selected([$this->mk->id], '2026-10-01'), $this->admin);
    $tooEarly = $this->applicability->plan($this->engineer, ApplicabilitySelection::selected([$this->mk->id], '2026-09-30'), $this->admin);

    expect($within->blockers)->toBe([])
        ->and($within->backdated())->toBeTrue()
        ->and($within->earliest)->toBe('2026-10-01')
        ->and($tooEarly->blockers)->toContain('It can start on 1 Oct 2026 at the earliest: a change can reach back to the start of the previous payroll month (Oct 2026) at most. Choose that day or a later one.')
        ->and($this->applicability->earliestStartFor($this->engineer, ApplicabilitySelection::selected([$this->mk->id]))['date'])->toBe('2026-10-01');

    // Today and later are not back-dated at all.
    $today = $this->applicability->plan($this->engineer, ApplicabilitySelection::selected([$this->mk->id], '2026-11-23'), $this->admin);
    expect($today->backdated())->toBeFalse()->and($today->blockers)->toBe([])->and($today->earliest)->toBeNull();
});

it('never reaches a month whose payroll is finalized, for the companies the change touches only', function () {
    ($this->finalizeOctober)($this->fe);

    // Leaving FynnEdge touches FynnEdge: its finalized October stops the change at 1 Nov.
    $leaving = $this->applicability->plan($this->engineer, ApplicabilitySelection::selected([$this->mk->id], '2026-10-15'), $this->admin);
    expect($leaving->blockers)->toContain('It can start on 1 Nov 2026 at the earliest: payroll for Oct 2026 is finalized for FynnEdge Advisory. Choose that day or a later one.')
        ->and($this->applicability->plan($this->engineer, ApplicabilitySelection::selected([$this->mk->id], '2026-11-01'), $this->admin)->blockers)->toBe([]);

    // Leaving only Third Labs does not touch FynnEdge: October is still open for the company it touches.
    expect($this->applicability->plan($this->engineer, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id], '2026-10-15'), $this->admin)->blockers)->toBe([]);
});

it('never reaches a closed attendance month; a closed current month allows no back-dating', function () {
    app(SettingsRepository::class)->set(AttendanceMonthCloses::SETTING, true);
    AttendanceMonthClose::query()->create(['company_id' => $this->mk->id, 'year' => 2026, 'month' => 11, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30',
        'status' => 'closed', 'closed_by' => $this->admin->id, 'closed_at' => now()]);

    $plan = $this->applicability->plan($this->engineer, ApplicabilitySelection::selected([$this->fe->id], '2026-11-20'), $this->admin);
    expect($plan->blockers)->toContain('It can start on 23 Nov 2026 at the earliest: attendance for Nov 2026 is closed for Markedge Technologies. Choose that day or a later one.')
        ->and($this->applicability->earliestStartFor($this->engineer, ApplicabilitySelection::selected([$this->fe->id]))['date'])->toBe('2026-11-23');
});

it('keeps history in order: never before the last change to where it applies', function () {
    $this->travelTo('2026-11-20 09:00:00');
    $this->applicability->apply($this->engineer, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin, 'Group engineering');
    $this->travelTo('2026-11-23 10:00:00');

    expect($this->applicability->plan($this->engineer, ApplicabilitySelection::selected([$this->mk->id], '2026-11-10'), $this->admin)->blockers)
        ->toContain('It can start on 20 Nov 2026 at the earliest: where it applies last changed on 20 Nov 2026. Choose that day or a later one.')
        ->and($this->applicability->plan($this->engineer, ApplicabilitySelection::selected([$this->mk->id], '2026-11-21'), $this->admin)->blockers)->toBe([]);
});

it('needs a reason, writes true history from the chosen day and audits it as back-dated', function () {
    expect(fn () => $this->applicability->apply($this->engineer, ApplicabilitySelection::selected([$this->mk->id], '2026-11-01'), $this->admin))
        ->toThrow(ApplicabilityRefusedException::class);
    expect(ConfigurationApplicabilityCompany::query()->count())->toBe(0);

    $plan = $this->applicability->apply($this->engineer, ApplicabilitySelection::selected([$this->mk->id], '2026-11-01'), $this->admin, 'Engineering moved to Markedge on 1 Nov');

    expect($plan->removed)->toBe([$this->fe->id, $this->th->id])
        ->and($this->applicability->appliesTo($this->engineer, $this->fe->id, '2026-10-31'))->toBeTrue()
        ->and($this->applicability->appliesTo($this->engineer, $this->fe->id, '2026-11-01'))->toBeFalse()
        ->and($this->applicability->appliesTo($this->engineer, $this->mk->id, '2026-11-01'))->toBeTrue()
        ->and(ConfigurationApplicabilityCompany::query()->where('company_id', $this->fe->id)->sole()->effective_to->toDateString())->toBe('2026-10-31');

    $event = AuditEvent::query()->where('action', AuditAction::ApplicabilityChanged->value)->sole();
    expect($event->reason)->toBe('Engineering moved to Markedge on 1 Nov')
        ->and($event->effective_date->toDateString())->toBe('2026-11-01')
        ->and($event->metadata['backdated'])->toBeTrue()
        ->and($event->metadata['earliest'])->toBe('2026-10-01')
        ->and(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue();
});

it('offers earlier days on the Applies to… screen down to the earliest allowed, with a tick and a reason', function () {
    $hr = Department::factory()->create(['name' => 'Human Resources', 'code' => 'HR', 'company_id' => $this->mk->id]);
    $list = fn () => Livewire\Livewire::test(ListDepartments::class);

    // Before the open payroll months: refused by the calendar's first day.
    $list()->callTableAction('manageApplicability', $hr, ['mode' => 'selected', 'companies' => [$this->mk->id, $this->fe->id], 'effective_from' => '2026-09-30',
        'confirmed' => true, 'backdated_confirmed' => true, 'audit_reason' => 'Too early'])
        ->assertHasTableActionErrors(['effective_from']);
    // Back-dated without the tick and the reason: not made.
    $list()->callTableAction('manageApplicability', $hr, ['mode' => 'selected', 'companies' => [$this->mk->id, $this->fe->id], 'effective_from' => '2026-11-01', 'confirmed' => true])
        ->assertHasTableActionErrors(['backdated_confirmed', 'audit_reason']);
    expect(app(EntityApplicability::class)->reach($hr))->toBe([$this->mk->id]);

    $list()->callTableAction('manageApplicability', $hr, ['mode' => 'selected', 'companies' => [$this->mk->id, $this->fe->id], 'effective_from' => '2026-11-01',
        'confirmed' => true, 'backdated_confirmed' => true, 'audit_reason' => 'FynnEdge HR joined on 1 Nov'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Applies to Markedge Technologies, FynnEdge Advisory');
    expect(app(EntityApplicability::class)->appliesTo($hr, $this->fe->id, '2026-11-01'))->toBeTrue()
        ->and(app(EntityApplicability::class)->appliesTo($hr, $this->fe->id, '2026-10-31'))->toBeFalse();
});
