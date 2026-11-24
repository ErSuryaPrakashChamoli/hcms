<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Configuration\Applicability\ApplicabilityMode;
use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Models\ConfigurationApplicability;
use App\Domain\Configuration\Models\ConfigurationApplicabilityCompany;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use Illuminate\Support\Facades\DB;

/*
| Configuration applicability, shared configuration (docs/architecture/configuration-applicability.md, acceptance 13.1,
| 13.5 and Part 17 "Organisation masters" / "Shared configuration"). Markedge (MK) and FynnEdge (FE) share masters; Third
| Labs (TH) is the company left out.
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
    $this->hr = Department::factory()->create(['name' => 'Human Resources', 'code' => 'HR', 'company_id' => $this->mk->id]);
    $this->engineer = Designation::factory()->create(['name' => 'Engineer', 'code' => 'ENG']);

    $this->hireInto = fn (Company $company, array $position = []) => app(HireEmployeeAction::class)->handle(
        ['first_name' => fake()->firstName(), 'last_name' => 'Shared'],
        ['joining_date' => '2026-01-01'],
        ['company_id' => $company->id] + $position,
    );
});

it('keeps the earlier meaning for records whose companies were never chosen', function () {
    $shared = Department::factory()->create(['code' => 'ALL', 'company_id' => null]);

    expect($this->applicability->reach($this->engineer))->toBeNull()
        ->and($this->applicability->reach($this->hr))->toBe([$this->mk->id])
        ->and($this->applicability->reach($shared))->toBeNull()
        ->and($this->applicability->isExplicit($this->hr))->toBeFalse()
        ->and($this->applicability->constrain(Department::query(), $this->fe->id)->pluck('code')->all())->toBe(['ALL'])
        ->and($this->applicability->constrain(Department::query(), $this->mk->id)->orderBy('code')->pluck('code')->all())->toBe(['ALL', 'HR'])
        ->and($this->applicability->summary($this->engineer))->toBe('All entities')
        ->and($this->applicability->summary($this->hr))->toBe('Markedge Technologies only');
    // Legacy records are not checked on positions: nothing that worked before stops working.
    expect(fn () => ($this->hireInto)($this->fe, ['department_id' => $this->hr->id]))->not->toThrow(ApplicabilityRefusedException::class);
});

it('creates a department once and applies it to Markedge and FynnEdge (one logical record, one audit event)', function () {
    $plan = $this->applicability->apply($this->hr, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin, 'Group HR');

    expect($plan->added)->toBe([$this->fe->id])
        ->and($plan->removed)->toBe([])
        ->and($this->applicability->reach($this->hr))->toBe([$this->mk->id, $this->fe->id])
        ->and(Department::query()->where('name', 'Human Resources')->count())->toBe(1)
        ->and($this->applicability->constrain(Department::query(), $this->fe->id)->pluck('id')->all())->toBe([$this->hr->id])
        ->and($this->applicability->constrain(Department::query(), $this->th->id)->pluck('id')->all())->toBe([])
        ->and($this->applicability->summary($this->hr))->toBe('Markedge Technologies, FynnEdge Advisory');

    // History: Markedge's earlier row is open at the start, FynnEdge joins today.
    $rows = ConfigurationApplicabilityCompany::query()->orderBy('company_id')->get();
    expect($rows->map(fn ($r) => [$r->company_id, $r->effective_from?->toDateString(), $r->source])->all())->toBe([
        [$this->mk->id, null, 'earlier'], [$this->fe->id, '2026-11-23', 'chosen'],
    ]);

    // Both companies' people sit in the same record.
    $mkPerson = ($this->hireInto)($this->mk, ['department_id' => $this->hr->id]);
    $fePerson = ($this->hireInto)($this->fe, ['department_id' => $this->hr->id]);
    expect([$mkPerson->positions()->value('department_id'), $fePerson->positions()->value('department_id')])
        ->toBe([$this->hr->id, $this->hr->id]);

    // A shared change reaches both, because there is only one record.
    $this->hr->update(['name' => 'People & Culture']);
    expect(Department::query()->whereKey($this->hr->id)->value('name'))->toBe('People & Culture');

    $event = AuditEvent::query()->where('action', AuditAction::ApplicabilityChanged->value)->sole();
    expect($event->reason)->toBe('Group HR')
        ->and($event->metadata['added'])->toBe([$this->fe->id])
        ->and(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue();
});

it('applies to all entities, with or without companies created later', function () {
    $this->applicability->apply($this->engineer, ApplicabilitySelection::all(includeFuture: true), $this->admin);
    $level = Level::factory()->create(['code' => 'LVX']);
    $this->applicability->apply($level, ApplicabilitySelection::all(includeFuture: false), $this->admin);

    $this->travelTo('2026-12-01 09:00:00');
    $new = Company::factory()->create(['name' => 'Newco', 'code' => 'NEW']);

    expect($this->applicability->appliesTo($this->engineer, $new->id))->toBeTrue()
        ->and($this->applicability->appliesTo($this->engineer, $new->id, '2026-11-30'))->toBeFalse()
        ->and($this->applicability->appliesTo($level, $new->id))->toBeFalse()
        ->and($this->applicability->reach($level))->toBe([$this->mk->id, $this->fe->id, $this->th->id])
        ->and($this->applicability->summary($level))->toBe('All entities (not later ones)')
        ->and(ConfigurationApplicabilityCompany::query()->where('company_id', $new->id)->sole()->source)->toBe(ConfigurationApplicabilityCompany::FUTURE)
        ->and(AuditEvent::query()->where('action', AuditAction::ApplicabilityChanged->value)->where('entity_type', Company::class)->count())->toBe(1);
});

it('removes a company safely: blocked while its people use the record, history kept afterwards (13.1, 13.5)', function () {
    $this->applicability->apply($this->hr, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin);
    $fePerson = ($this->hireInto)($this->fe, ['department_id' => $this->hr->id]);
    $positionsBefore = DB::table('employee_positions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    $plan = $this->applicability->plan($this->hr, ApplicabilitySelection::single($this->mk->id), $this->admin);
    expect($plan->blocked())->toBeTrue()
        ->and($plan->blockers[0])->toContain('1 current positions in FynnEdge Advisory still use this department');
    expect(fn () => $this->applicability->apply($this->hr, ApplicabilitySelection::single($this->mk->id), $this->admin))->toThrow(ApplicabilityRefusedException::class);
    expect(DB::table('employee_positions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($positionsBefore);

    // Move the person out (from tomorrow), then FynnEdge can leave from the day after the move.
    $other = Department::factory()->create(['code' => 'FIN', 'company_id' => $this->fe->id]);
    app(AssignPositionAction::class)->handle($fePerson, ['department_id' => $other->id], 'transfer', '2026-11-24');
    $this->applicability->apply($this->hr, new ApplicabilitySelection(ApplicabilityMode::Single, [$this->mk->id], false, '2026-11-24'), $this->admin, 'FynnEdge has its own');

    expect($this->applicability->appliesTo($this->hr, $this->fe->id, '2026-11-23'))->toBeTrue()
        ->and($this->applicability->appliesTo($this->hr, $this->fe->id, '2026-11-24'))->toBeFalse()
        // The historical position still points at the department; nothing was moved or deleted.
        ->and(EmployeePosition::query()->where('employee_id', $fePerson->id)->orderBy('id')->first()->department_id)->toBe($this->hr->id)
        ->and(ConfigurationApplicabilityCompany::query()->where('company_id', $this->fe->id)->sole()->effective_to->toDateString())->toBe('2026-11-23');
});

it('refuses applicability that starts in the past, or leaves out the owning company', function () {
    $past = $this->applicability->plan($this->engineer, new ApplicabilitySelection(ApplicabilityMode::Selected, [$this->mk->id], false, '2026-11-01'), $this->admin);
    $owner = $this->applicability->plan($this->hr, ApplicabilitySelection::single($this->fe->id), $this->admin);
    $nobody = $this->applicability->plan($this->engineer, ApplicabilitySelection::selected([]), $this->admin);

    expect($past->blockers)->toContain('Applicability cannot start in the past: past transactions keep what applied to them. Choose today or a later date.')
        ->and($owner->blockers[0])->toContain('belongs to Markedge Technologies, so it always applies there')
        ->and($nobody->blockers)->toContain('Choose at least one company.')
        ->and(ConfigurationApplicability::query()->count())->toBe(0);
});

it('refuses positions in a company the record does not apply to, and filters pickers by company', function () {
    $this->applicability->apply($this->engineer, ApplicabilitySelection::single($this->mk->id), $this->admin);

    expect(fn () => ($this->hireInto)($this->fe, ['designation_id' => $this->engineer->id]))
        ->toThrow(ApplicabilityRefusedException::class, 'Designation "Engineer (ENG)" does not apply to FynnEdge Advisory.');
    expect(Employee::query()->count())->toBe(0);

    $mk = ($this->hireInto)($this->mk, ['designation_id' => $this->engineer->id]);
    expect($mk->positions()->value('designation_id'))->toBe($this->engineer->id)
        ->and(array_keys($this->applicability->options(Designation::class, $this->fe->id)))->not->toContain($this->engineer->id)
        ->and(array_keys($this->applicability->options(Designation::class, $this->mk->id)))->toContain($this->engineer->id)
        ->and(array_keys($this->applicability->options(Designation::class, null)))->toContain($this->engineer->id);
});

it('validates dependencies both ways: what it uses, and what uses it (Part 12)', function () {
    $grade = Grade::factory()->create(['code' => 'G1']);
    $this->engineer->update(['grade_id' => $grade->id]);

    // Reverse: the grade cannot leave FynnEdge and Third while a designation using it still applies there.
    $reverse = $this->applicability->plan($grade, ApplicabilitySelection::single($this->mk->id), $this->admin);
    expect($reverse->blockers)->toBe(['The Designation "Engineer (ENG)" uses this grade in FynnEdge Advisory and Third Labs. Change it there first.']);

    // Narrow the designation first, then the grade follows.
    $this->applicability->apply($this->engineer, ApplicabilitySelection::single($this->mk->id), $this->admin);
    $this->applicability->apply($grade, ApplicabilitySelection::single($this->mk->id), $this->admin);

    // Forward: the designation cannot reach FynnEdge while its grade does not.
    $forward = $this->applicability->plan($this->engineer, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin);
    expect($forward->blockers)->toHaveCount(1)
        ->and($forward->blockers[0])->toContain('It uses the Grade')->toContain('(G1)", which FynnEdge Advisory cannot use');

    // Sharing the grade first unblocks it.
    $this->applicability->apply($grade, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin);
    expect($this->applicability->plan($this->engineer, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin)->blockers)->toBe([]);
});

it('gives a policy limited to some companies only to their people, whatever its rules say', function () {
    $policies = app(Policies::class);
    $standard = Policy::create(['type' => 'leave', 'name' => 'Standard', 'code' => 'STD']);
    $policies->draft($standard, ['count_weekly_offs' => false]);
    $policies->publish($standard, '2026-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'leave', 'policy_id' => $standard->id, 'name' => 'Everyone', 'priority' => 1000, 'conditions' => []]);
    $group = Policy::create(['type' => 'leave', 'name' => 'Group leave', 'code' => 'GRP']);
    $policies->draft($group, ['count_weekly_offs' => true]);
    $policies->publish($group, '2026-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'leave', 'policy_id' => $group->id, 'name' => 'Everyone', 'priority' => 10, 'conditions' => []]);

    $mk = ($this->hireInto)($this->mk);
    $fe = ($this->hireInto)($this->fe);
    $th = ($this->hireInto)($this->th);
    $resolver = app(PolicyResolver::class);
    expect([$resolver->resolve('leave', $mk)->policy->code, $resolver->resolve('leave', $th)->policy->code])->toBe(['GRP', 'GRP']);

    $this->applicability->apply($group, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin);

    expect([$resolver->resolve('leave', $mk)->policy->code, $resolver->resolve('leave', $fe)->policy->code, $resolver->resolve('leave', $th)->policy->code])
        ->toBe(['GRP', 'GRP', 'STD']);
});
