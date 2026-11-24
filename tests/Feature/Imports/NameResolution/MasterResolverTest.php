<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Resolution\MasterCatalogue;
use App\Domain\Organisation\Resolution\MasterResolver;
use App\Domain\Organisation\Resolution\Models\MasterReferenceMapping;
use App\Domain\Organisation\Resolution\NameKey;
use App\Domain\Organisation\Resolution\ReferenceMappings;
use App\Domain\Organisation\Resolution\Resolution;
use App\Domain\Organisation\Resolution\ResolutionRefusedException;
use App\Domain\Organisation\Resolution\ResolutionReport;
use App\Domain\Organisation\Resolution\ResolutionSession;
use App\Domain\Organisation\Resolution\ResolutionStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
| Name-based import resolution (docs/architecture/name-resolution.md): "users manage names, PeopleOS manages codes".
|
| Markedge Technologies (MK) and FynnEdge Advisory (FE) each own a "Human Resources" department; Markedge also has
| "Sales & Business Development" and an inactive "Legacy Ops"; "Finance" is shared (no company). Designations and
| levels are tenant-wide.
*/

beforeEach(function () {
    $this->travelTo('2026-11-23 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);

    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->hrMk = Department::factory()->create(['name' => 'Human Resources', 'code' => 'HR', 'company_id' => $this->mk->id]);
    $this->hrFe = Department::factory()->create(['name' => 'Human Resources', 'code' => 'FE-HR', 'company_id' => $this->fe->id]);
    $this->sbd = Department::factory()->create(['name' => 'Sales & Business Development', 'code' => 'SBD', 'company_id' => $this->mk->id]);
    $this->legacy = Department::factory()->create(['name' => 'Legacy Ops', 'code' => 'LOPS', 'company_id' => $this->mk->id, 'status' => 'inactive']);
    $this->finance = Department::factory()->create(['name' => 'Finance', 'code' => 'FIN', 'company_id' => null]);
    $this->hrManager = Designation::factory()->create(['name' => 'HR Manager', 'code' => 'HRM']);
    $this->sourcing = Designation::factory()->create(['name' => 'Sourcing Manager', 'code' => 'SRC']);
    $this->hrExec = Designation::factory()->create(['name' => 'HR Executive', 'code' => 'HRE']);
    // Seeded by provisioning: name and code are both "L5", the commonest way a value is a name and a code at once.
    $this->l5 = Level::query()->where('code', 'L5')->firstOrFail();
    $this->l2 = Level::query()->where('code', 'L2')->firstOrFail();

    $this->resolver = app(MasterResolver::class);
    $this->session = fn (?object $actor = null, ?string $scope = null): ResolutionSession => $this->resolver->session($actor, $scope);
});

it('resolves the example rows by name, each against its own company', function () {
    $s = ($this->session)($this->admin, 'employee_imports:1');
    $rows = [
        ['Rahul Sharma', $this->mk, 'Human Resources', 'HR Manager', 'L5', $this->hrMk],
        ['Amit Verma', $this->mk, 'Sales & Business Development', 'Sourcing Manager', 'L5', $this->sbd],
        ['Priya Singh', $this->fe, 'Human Resources', 'HR Executive', 'L2', $this->hrFe],
    ];
    foreach ($rows as $i => [, $company, $department, $designation, $level, $expected]) {
        $c = $s->resolve('company', $company->name, sheet: 'Employees', row: $i + 2);
        $d = $s->resolve('department', $department, companyId: $c->id, sheet: 'Employees', row: $i + 2, column: 'Department');
        $g = $s->resolve('designation', $designation, companyId: $c->id);
        $l = $s->resolve('level', $level, companyId: $c->id);

        expect($c->status)->toBe(ResolutionStatus::Matched)->and($c->id)->toBe($company->id)
            ->and($d->status)->toBe(ResolutionStatus::Matched)->and($d->id)->toBe($expected->id)->and($d->via)->toBe(Resolution::VIA_NAME)
            ->and($g->ok())->toBeTrue()->and($l->via)->toBe(Resolution::VIA_CODE)->and($l->error())->toBeNull();
    }
    expect($s->resolve('designation', 'HR Manager')->id)->toBe($this->hrManager->id)
        ->and($s->resolve('level', 'L2')->id)->toBe($this->l2->id);
});

it('ignores spacing, case and spreadsheet punctuation, and nothing else', function () {
    $s = ($this->session)();
    $special = Department::factory()->create(['name' => "Founder's Office", 'code' => 'FO', 'company_id' => $this->mk->id]);

    expect($s->resolve('department', "  human \t RESOURCES ", companyId: $this->mk->id)->id)->toBe($this->hrMk->id)
        ->and($s->resolve('department', "Founder\u{2019}s\u{00A0}Office", companyId: $this->mk->id)->id)->toBe($special->id)
        ->and(NameKey::key("Sales \u{2013} North"))->toBe(NameKey::key('sales - north'))
        // "and" for "&" is close, not the same name: offered, never chosen.
        ->and($s->resolve('department', 'Sales and Business Development', companyId: $this->mk->id)->status)->toBe(ResolutionStatus::Suggested);
});

it('keeps every code-based file resolving exactly as before', function () {
    $s = ($this->session)();
    foreach ([['department', $this->hrMk], ['department', $this->hrFe], ['department', $this->legacy], ['designation', $this->sourcing], ['level', $this->l5]] as [$master, $record]) {
        foreach ([$record->code, strtolower($record->code), ' '.strtoupper($record->code).' '] as $typed) {
            $legacy = $record::query()->whereRaw('lower(code) = ?', [mb_strtolower(trim($typed))])->value('id');
            $r = $s->resolve($master, $typed);
            expect($r->id)->toBe($legacy)->and($r->via)->toBe(Resolution::VIA_CODE)->and($r->ok())->toBeTrue();
        }
    }
});

it('prefers the code when the same text is a code and another record\'s name, and says so', function () {
    $s = ($this->session)();
    $named = Department::factory()->create(['name' => 'FIN', 'code' => 'FINOPS', 'company_id' => null]);

    $r = $s->resolve('department', 'FIN');
    expect($r->id)->toBe($this->finance->id)->and($r->via)->toBe(Resolution::VIA_CODE)
        ->and($r->warnings())->toHaveCount(1)->and($r->warnings()[0])->toContain('also the name of FIN (FINOPS)')
        ->and($named->id)->not->toBe($r->id);
});

it('checks a name and a code given together describe one record', function () {
    $s = ($this->session)();

    expect($s->resolve('department', companyId: $this->mk->id, name: 'Human Resources', code: 'HR')->id)->toBe($this->hrMk->id);

    $clash = $s->resolve('department', companyId: $this->mk->id, name: 'Finance', code: 'HR');
    expect($clash->status)->toBe(ResolutionStatus::Conflict)->and($clash->id)->toBeNull()
        ->and($clash->error())->toBe("Department code 'HR' is Human Resources (HR), but the name says 'Finance'. Make the name and the code describe the same department, or give only one of them.");

    $typo = $s->resolve('department', companyId: $this->mk->id, name: 'Human Resource', code: 'HR');
    expect($typo->status)->toBe(ResolutionStatus::Conflict);

    $badCode = $s->resolve('department', companyId: $this->mk->id, name: 'Human Resources', code: 'NOPE');
    expect($badCode->status)->toBe(ResolutionStatus::Conflict)->and($badCode->error())->toContain("code 'NOPE' does not exist, but the name 'Human Resources' is Human Resources (HR)");
});

it('reports several records of one name as ambiguous and never picks one', function () {
    $s = ($this->session)();
    Designation::factory()->create(['name' => 'Analyst', 'code' => 'AN1']);
    Designation::factory()->create(['name' => 'analyst ', 'code' => 'AN2']);

    $r = $s->resolve('designation', 'Analyst');
    expect($r->status)->toBe(ResolutionStatus::Ambiguous)->and($r->id)->toBeNull()
        ->and(array_map(fn ($c) => $c->code, $r->candidates))->toEqualCanonicalizing(['AN1', 'AN2'])
        ->and($r->error())->toContain('matches 2 records')->and($r->error())->toContain('Use the code instead');

    // Each company owns a "Human Resources": with the row's company there is one; without it, two.
    expect($s->resolve('department', 'Human Resources', companyId: $this->fe->id)->id)->toBe($this->hrFe->id)
        ->and($s->resolve('department', 'Human Resources')->status)->toBe(ResolutionStatus::Ambiguous);
});

it('suggests near misses but never applies them', function () {
    $r = ($this->session)()->resolve('department', 'Human Resource', companyId: $this->mk->id);

    expect($r->status)->toBe(ResolutionStatus::Suggested)->and($r->id)->toBeNull()->and($r->ok())->toBeFalse()
        ->and($r->candidates[0]->id)->toBe($this->hrMk->id)
        ->and($r->error())->toBe("Department 'Human Resource' does not exist for Markedge Technologies. Did you mean Human Resources (HR), Markedge Technologies? Confirm it in the review, or correct the file.")
        // FynnEdge's namesake is not offered to a Markedge row.
        ->and(collect($r->candidates)->pluck('id')->all())->not->toContain($this->hrFe->id);

    $none = ($this->session)()->resolve('department', 'Unknown Department', companyId: $this->mk->id);
    expect($none->status)->toBe(ResolutionStatus::Unmatched)->and($none->candidates)->toBe([])
        ->and($none->error())->toBe("Department 'Unknown Department' does not exist for Markedge Technologies.");
});

it('applies entity applicability: a record must apply to the row\'s company', function () {
    $s = ($this->session)();
    $partner = Department::factory()->create(['name' => 'Partner Desk', 'code' => 'PD', 'company_id' => $this->fe->id]);

    $byName = $s->resolve('department', 'Partner Desk', companyId: $this->mk->id);
    expect($byName->status)->toBe(ResolutionStatus::NotApplicable)
        ->and($byName->error())->toBe("Department 'Partner Desk' (Partner Desk (PD)) belongs to FynnEdge Advisory, not Markedge Technologies.");
    expect($s->resolve('department', 'PD', companyId: $this->mk->id)->status)->toBe(ResolutionStatus::NotApplicable)
        // Shared (no company) applies to both.
        ->and($s->resolve('department', 'Finance', companyId: $this->fe->id)->id)->toBe($this->finance->id);

    // A tenant-wide designation limited to Markedge through the applicability framework.
    app(EntityApplicability::class)->apply($this->hrManager, ApplicabilitySelection::single($this->mk->id), $this->admin, 'Markedge only');
    $fresh = ($this->session)();
    expect($fresh->resolve('designation', 'HR Manager', companyId: $this->mk->id)->id)->toBe($this->hrManager->id)
        ->and($fresh->resolve('designation', 'HR Manager', companyId: $this->fe->id)->status)->toBe(ResolutionStatus::NotApplicable)
        ->and($fresh->resolve('designation', 'HRM', companyId: $partner->company_id)->error())->toContain('is not available to FynnEdge Advisory');
});

it('applies the actor\'s organisation access and never names what they cannot see', function () {
    $mkHr = tenantUser($this->tenant, ['organisation.view', 'employee.view']);
    app(AccessScopes::class)->assign($mkHr, ['company' => [$this->mk->id]], 'Markedge HR');

    // Checked as the uploader with nobody signed in (a queued re-check), not through the signed-in user.
    auth()->logout();
    $s = ($this->session)($mkHr->fresh());

    expect($s->resolve('department', 'FE-HR')->status)->toBe(ResolutionStatus::Unmatched)
        ->and($s->resolve('department', 'Human Resources')->id)->toBe($this->hrMk->id);
    // For a FynnEdge row only Markedge's namesake is visible: it is named (they can see it), FynnEdge's never is.
    $miss = $s->resolve('department', 'Human Resources', companyId: $this->fe->id);
    expect($miss->status)->toBe(ResolutionStatus::NotApplicable)
        ->and(collect($miss->candidates)->pluck('id')->all())->toBe([$this->hrMk->id])
        ->and($miss->error())->toContain('belongs to Markedge Technologies')->and($miss->error())->not->toContain('FE-HR');
    $typo = $s->resolve('department', 'Human Resource', companyId: $this->fe->id);
    expect($typo->status)->toBe(ResolutionStatus::Unmatched)->and($typo->candidates)->toBe([]);
    // The system (no actor) still sees everything.
    expect(($this->session)()->resolve('department', 'FE-HR')->id)->toBe($this->hrFe->id);
});

it('prefers the live record among namesakes and warns about an inactive match', function () {
    $s = ($this->session)();
    $old = Department::factory()->create(['name' => 'Operations', 'code' => 'OPS-OLD', 'company_id' => $this->mk->id, 'status' => 'inactive']);
    $new = Department::factory()->create(['name' => 'Operations', 'code' => 'OPS', 'company_id' => $this->mk->id]);

    expect($s->resolve('department', 'Operations', companyId: $this->mk->id)->id)->toBe($new->id);
    $inactive = $s->resolve('department', 'Legacy Ops', companyId: $this->mk->id);
    expect($inactive->id)->toBe($this->legacy->id)->and($inactive->warnings()[0])->toContain('inactive')->and($old->id)->not->toBe($new->id);
});

it('narrows names by the record\'s own columns', function () {
    $s = ($this->session)();
    $a = Department::factory()->create(['name' => 'Recruitment', 'code' => 'REC-A', 'company_id' => $this->mk->id, 'parent_department_id' => $this->hrMk->id]);
    Department::factory()->create(['name' => 'Recruitment', 'code' => 'REC-B', 'company_id' => $this->mk->id, 'parent_department_id' => $this->sbd->id]);

    expect($s->resolve('department', 'Recruitment', companyId: $this->mk->id)->status)->toBe(ResolutionStatus::Ambiguous)
        ->and($s->resolve('department', 'Recruitment', companyId: $this->mk->id, within: ['parent_department_id' => $this->hrMk->id])->id)->toBe($a->id)
        ->and(fn () => $s->resolve('department', 'Recruitment', within: ['status' => 1]))->toThrow(InvalidArgumentException::class);
});

it('maps a value for one upload, or remembers it, and retires it', function () {
    $mappings = app(ReferenceMappings::class);
    $mapping = $mappings->map($this->admin, 'department', 'Human Resource', $this->hrMk->id, $this->mk->id, 'employee_imports:7');

    expect(($this->session)($this->admin, 'employee_imports:7')->resolve('department', 'Human Resource', companyId: $this->mk->id))
        ->id->toBe($this->hrMk->id)->via->toBe(Resolution::VIA_MAPPING)
        // Another upload does not see it; a FynnEdge row does not use a Markedge mapping.
        ->and(($this->session)($this->admin, 'employee_imports:8')->resolve('department', 'Human Resource', companyId: $this->mk->id)->status)->toBe(ResolutionStatus::Suggested)
        ->and(($this->session)($this->admin, 'employee_imports:7')->resolve('department', 'Human Resource', companyId: $this->fe->id)->status)->toBe(ResolutionStatus::Suggested);

    $remembered = $mappings->map($this->admin, 'department', 'HR Dept', $this->hrMk->id, $this->mk->id, null, remember: true);
    expect(($this->session)($this->admin, 'employee_imports:99')->resolve('department', 'hr dept', companyId: $this->mk->id)->id)->toBe($this->hrMk->id)
        ->and($remembered->remembered())->toBeTrue();

    // Re-mapping replaces (the old one is retired, not deleted); retiring returns the value to unmatched.
    $again = $mappings->map($this->admin, 'department', 'Human Resource', $this->finance->id, $this->mk->id, 'employee_imports:7');
    expect($mapping->fresh()->status)->toBe(MasterReferenceMapping::RETIRED)->and($mapping->fresh()->active_key)->toBeNull();
    $mappings->retire($this->admin, $again, 'Wrong department');
    expect(($this->session)($this->admin, 'employee_imports:7')->resolve('department', 'Human Resource', companyId: $this->mk->id)->status)->toBe(ResolutionStatus::Suggested)
        ->and(MasterReferenceMapping::query()->count())->toBe(3)
        ->and(AuditEvent::query()->withoutTenancy()->where('entity_type', MasterReferenceMapping::class)->where('action', AuditAction::Create)->count())->toBe(3);
});

it('lets a mapping settle an ambiguous name', function () {
    Designation::factory()->create(['name' => 'Analyst', 'code' => 'AN1']);
    $two = Designation::factory()->create(['name' => 'Analyst', 'code' => 'AN2']);
    app(ReferenceMappings::class)->map($this->admin, 'designation', 'Analyst', $two->id, null, 'employee_imports:3');

    expect(($this->session)($this->admin, 'employee_imports:3')->resolve('designation', 'Analyst'))->id->toBe($two->id)->via->toBe(Resolution::VIA_MAPPING);
});

it('refuses mappings that would be unsafe', function () {
    $mappings = app(ReferenceMappings::class);
    $viewer = tenantUser($this->tenant, ['organisation.view']);

    expect(fn () => $mappings->map($viewer, 'department', 'HR Dept', $this->hrMk->id, $this->mk->id, null, remember: true))
        ->toThrow(ResolutionRefusedException::class, 'needs the right to manage Department records')
        ->and(fn () => $mappings->map($this->admin, 'department', 'HR Dept', $this->hrFe->id, $this->mk->id, 'employee_imports:1'))
        ->toThrow(ResolutionRefusedException::class, 'does not apply to Markedge Technologies')
        ->and(fn () => $mappings->map($this->admin, 'department', 'Finance', $this->hrMk->id, $this->mk->id, 'employee_imports:1'))
        ->toThrow(ResolutionRefusedException::class, "'Finance' is already the exact name of Finance (FIN)")
        ->and(fn () => $mappings->map($this->admin, 'department', 'HR Dept', $this->hrMk->id, $this->mk->id, null))
        ->toThrow(ResolutionRefusedException::class, 'belongs to one upload')
        ->and(fn () => $mappings->map($this->admin, 'employee', 'Rahul S', 1, null, null, remember: true))
        ->toThrow(ResolutionRefusedException::class, 'never remembered for a person');
});

it('creates a missing master only when asked, with the master\'s own right, and never twice', function () {
    $mappings = app(ReferenceMappings::class);
    $dept = $mappings->create($this->admin, 'department', 'Payroll  Operations', $this->mk->id);

    expect($dept)->toBeInstanceOf(Department::class)->and($dept->name)->toBe('Payroll Operations')->and($dept->code)->toBe('PAYROLL_OPERATIONS')
        ->and($dept->company_id)->toBe($this->mk->id)->and($dept->getRawOriginal('status'))->toBe('active')
        ->and(($this->session)()->resolve('department', 'payroll operations', companyId: $this->mk->id)->id)->toBe($dept->id)
        ->and(AuditEvent::query()->withoutTenancy()->where('entity_type', Department::class)->where('entity_id', $dept->id)->where('action', AuditAction::Create)->exists())->toBeTrue();

    // FynnEdge may have its own; Markedge may not get a second; a taken code gets a suffix.
    expect($mappings->create($this->admin, 'department', 'Payroll Operations', $this->fe->id)->code)->toBe('PAYROLL_OPERATIONS_2')
        ->and(fn () => $mappings->create($this->admin, 'department', 'PAYROLL operations', $this->mk->id))->toThrow(ResolutionRefusedException::class, 'already exists for this company');

    // A tenant-wide master applies to the row's company only, unless all companies are chosen explicitly.
    $designation = $mappings->create($this->admin, 'designation', 'Talent Partner', $this->mk->id);
    $s = ($this->session)();
    expect($s->resolve('designation', 'Talent Partner', companyId: $this->mk->id)->id)->toBe($designation->id)
        ->and($s->resolve('designation', 'Talent Partner', companyId: $this->fe->id)->status)->toBe(ResolutionStatus::NotApplicable)
        ->and(fn () => $mappings->create($this->admin, 'designation', 'Talent Partner', $this->fe->id))->toThrow(ResolutionRefusedException::class, 'Extend where it applies');
    $everywhere = $mappings->create($this->admin, 'designation', 'People Partner', null, allCompanies: true);
    expect(app(EntityApplicability::class)->reach($everywhere))->toEqualCanonicalizing([$this->mk->id, $this->fe->id]);
});

it('refuses to create without the right, for a rich master, or without a company', function () {
    $mappings = app(ReferenceMappings::class);
    $viewer = tenantUser($this->tenant, ['organisation.view', 'people_setup.view']);

    expect(fn () => $mappings->create($viewer, 'department', 'New Dept', $this->mk->id))->toThrow(ResolutionRefusedException::class, 'You may not add Department records')
        ->and(fn () => $mappings->create($this->admin, 'leave_type', 'Sabbatical', null))->toThrow(ResolutionRefusedException::class, 'created on their own screen')
        ->and(fn () => $mappings->create($this->admin, 'department', 'New Dept', null))->toThrow(ResolutionRefusedException::class, 'belongs to a company')
        ->and(Department::query()->where('name', 'New Dept')->exists())->toBeFalse();
});

it('matches records an earlier worksheet will create', function () {
    $s = ($this->session)();
    $s->plan('department', name: 'Payroll Ops', code: 'PAY', companyId: $this->mk->id);
    $s->plan('department', name: 'Human Resources', code: 'HR2', companyId: $this->mk->id);

    expect($s->resolve('department', 'Payroll Ops', companyId: $this->mk->id))->status->toBe(ResolutionStatus::Planned)->id->toBe(-1)
        ->and($s->resolve('department', 'PAY', companyId: $this->mk->id)->status)->toBe(ResolutionStatus::Planned)
        // A planned namesake of an existing department would create a duplicate: ambiguous, not silently either.
        ->and($s->resolve('department', 'Human Resources', companyId: $this->mk->id)->status)->toBe(ResolutionStatus::Ambiguous);
});

it('resolves employees by code, work e-mail or full name, never by one word', function () {
    $hire = fn (string $first, string $last, Company $company, string $code, ?string $email = null) => app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => $last], ['joining_date' => '2025-01-01', 'employee_code' => $code, 'work_email' => $email],
        ['company_id' => $company->id, 'department_id' => $company->is($this->mk) ? $this->hrMk->id : $this->hrFe->id],
    );
    $rahul = $hire('Rahul', 'Sharma', $this->mk, 'MK001', 'rahul@markedge.test');
    $rahulFe = $hire('Rahul', 'Sharma', $this->fe, 'FE001');
    $priya = $hire('Priya', 'Singh', $this->fe, 'FE002');
    $s = ($this->session)();

    expect($s->resolve('employee', 'mk001')->id)->toBe($rahul->id)
        ->and($s->resolve('employee', 'RAHUL@markedge.test')->id)->toBe($rahul->id)
        ->and($s->resolve('employee', 'priya  singh')->id)->toBe($priya->id)
        ->and($s->resolve('employee', 'Rahul Sharma')->status)->toBe(ResolutionStatus::Ambiguous)
        ->and($s->resolve('employee', 'Rahul Sharma', companyId: $this->fe->id)->id)->toBe($rahulFe->id)
        ->and($s->resolve('employee', 'Priya')->status)->toBe(ResolutionStatus::Unmatched)
        // A manager may sit in another company: a code is never refused for the row's company.
        ->and($s->resolve('employee', 'FE002', companyId: $this->mk->id)->id)->toBe($priya->id)
        ->and($s->resolve('employee', 'Priya Sing')->candidates)->toBe([]);
});

it('keeps organisations apart', function () {
    $other = provisionTenant('Other');
    actAsTenant($other);
    Company::factory()->create(['name' => 'Elsewhere', 'code' => 'EW']);
    $otherDept = Department::factory()->create(['name' => 'Treasury', 'code' => 'TRS']);
    actAsTenant($this->tenant);

    $s = ($this->session)();
    expect($s->resolve('department', 'Treasury')->status)->toBe(ResolutionStatus::Unmatched)
        ->and($s->resolve('department', 'TRS')->status)->toBe(ResolutionStatus::Unmatched)
        ->and($s->resolve('company', 'Elsewhere')->status)->toBe(ResolutionStatus::Unmatched)
        ->and($otherDept->id)->toBeGreaterThan(0);
    // Nor is it suggested or named: a near miss of the other organisation's record finds nothing to offer.
    $near = $s->resolve('department', 'Treasur');
    expect($near->status)->toBe(ResolutionStatus::Unmatched)->and($near->candidates)->toBe([])
        ->and($near->error())->not->toContain('Treasury')->not->toContain('TRS')
        // The report repeats what was typed, but names no record or candidate of the other organisation.
        ->and(json_encode(array_map(fn (array $e) => [$e['id'], $e['record'], $e['candidates']], $s->report()->entries())))
        ->not->toContain('Treasury')->not->toContain('TRS')->not->toContain('Elsewhere');

    actAsTenant($other);
    expect(fn () => $s->resolve('department', 'Treasury'))->toThrow(LogicException::class, 'belongs to one organisation');
});

it('builds the review table and keeps it across a round trip', function () {
    $s = ($this->session)($this->admin, 'employee_imports:5');
    foreach (range(2, 41) as $row) {
        $s->resolve('department', $row % 2 ? 'Human Resources' : 'Human Resource', companyId: $this->mk->id, sheet: 'Employees', row: $row, column: 'Department');
    }
    $s->resolve('department', 'Unknown Department', companyId: $this->mk->id, sheet: 'Employees', row: 50);
    $s->resolve('department', null, companyId: $this->mk->id);

    $report = $s->report();
    expect($report->entries())->toHaveCount(3)
        ->and($report->totals())->toEqual(['matched' => 20, 'suggested' => 20, 'unmatched' => 1])
        ->and($report->reviewable())->toHaveCount(2)->and($report->isClean())->toBeFalse()
        ->and($report->entries(ResolutionStatus::Suggested)[0]['at'])->toHaveCount(ResolutionReport::OCCURRENCES)
        ->and($report->entries(ResolutionStatus::Suggested)[0]['company'])->toBe('Markedge Technologies');

    $copy = ResolutionReport::fromArray(json_decode(json_encode($report->toArray()), true));
    expect($copy->toArray())->toBe($report->toArray())
        ->and($copy->merge(ResolutionReport::fromArray($report->toArray()))->totals())->toEqual(['matched' => 40, 'suggested' => 40, 'unmatched' => 2]);
});

it('costs a handful of queries however many rows a file has', function () {
    $s = ($this->session)($this->admin, 'employee_imports:9');
    $names = ['Human Resources', 'Sales & Business Development', 'Finance', 'HR', 'Human Resource'];
    $s->resolve('department', 'warm-up', companyId: $this->mk->id); // loads the master, its mappings and the company

    DB::enableQueryLog();
    foreach (range(1, 2000) as $i) {
        $s->resolve('department', $names[$i % 5], companyId: $this->mk->id, row: $i);
        $s->resolve('designation', 'HR Manager', companyId: $this->mk->id, row: $i);
    }
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 4 of the 5 department values match (the fifth is a near miss) plus every designation: 1600 + 2000.
    expect($queries)->toBeLessThanOrEqual(4)
        ->and($s->report()->totals())->toEqual(['matched' => 3600, 'suggested' => 400, 'unmatched' => 1]);
});

it('covers every registered configuration type, by key or model class', function () {
    $catalogue = app(MasterCatalogue::class);
    foreach (app(ConfigurationTypes::class)->all() as $key => $type) {
        expect($catalogue->keyFor($type->model))->toBe($key);
    }
    expect($catalogue->keyFor(Company::class))->toBe('company')->and($catalogue->keyFor(Employee::class))->toBe('employee')
        ->and(fn () => $catalogue->for('nonsense'))->toThrow(InvalidArgumentException::class)
        ->and(ReferenceMappings::codeFrom('Sales & Business Development'))->toBe('SALES_AND_BUSINESS_D');
});

it('fails closed without an organisation, with or without an actor', function () {
    app(TenantContext::class)->clear();

    expect(fn () => ($this->session)())->toThrow(LogicException::class, 'no tenant is bound')
        ->and(fn () => ($this->session)($this->admin))->toThrow(LogicException::class, 'no tenant is bound')
        ->and(fn () => $this->resolver->resolve('department', 'HR'))->toThrow(LogicException::class, 'no tenant is bound');
});

it('treats (none), the uploads\' "remove this link" value, as blank: never matched, suggested or reported', function () {
    // Even a record actually called "(none)" is not matched by it.
    Department::factory()->create(['name' => '(none)', 'code' => 'NONE_DEPT', 'company_id' => $this->mk->id]);
    $s = ($this->session)($this->admin, 'employee_imports:7');

    foreach (['(none)', ' (NONE) ', "(None)\u{00A0}"] as $typed) {
        expect($s->resolve('department', $typed, companyId: $this->mk->id))->status->toBe(ResolutionStatus::Blank)->id->toBeNull();
    }
    expect($s->resolve('department', name: '(none)', code: 'HR', companyId: $this->mk->id)->id)->toBe($this->hrMk->id)
        ->and($s->resolve('department', name: 'Human Resources', code: '(none)', companyId: $this->mk->id)->id)->toBe($this->hrMk->id)
        ->and($s->resolve('department', '(nothing)', companyId: $this->mk->id)->status)->toBe(ResolutionStatus::Unmatched)
        ->and($s->report()->entries())->toHaveCount(3)
        ->and(collect($s->report()->entries())->pluck('supplied')->all())->not->toContain('(none)');
});

it('fetches a large file\'s employee codes in batches when primed, with the same answers', function () {
    $ids = [];
    foreach (range(1, 12) as $i) {
        $code = sprintf('MK%03d', $i);
        $ids[$code] = app(HireEmployeeAction::class)->handle(['first_name' => 'Person', 'last_name' => "Number{$i}"],
            ['joining_date' => '2025-01-01', 'employee_code' => $code, 'work_email' => strtolower($code).'@markedge.test'],
            ['company_id' => $this->mk->id, 'department_id' => $this->hrMk->id])->id;
    }
    $column = [...array_keys($ids), 'mk999', 'MK005@markedge.test', '(none)', null, ''];
    $rows = array_map(fn (int $i) => $column[$i % count($column)], range(1, 10000));

    $unprimed = ($this->session)($this->admin);
    $expected = array_map(fn (?string $v) => $unprimed->resolve('employee', $v)->id, $column);

    $s = ($this->session)($this->admin, 'employee_imports:11');
    DB::enableQueryLog();
    $s->prime('employee', $rows);
    $primeQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    $answers = [];
    foreach ($rows as $i => $value) {
        $answers[$value ?? ''] = $s->resolve('employee', $value, row: $i + 2)->id;
    }
    $resolveQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One batch of codes and one of e-mails; then only the employee mappings are read, once.
    expect($primeQueries)->toBeLessThanOrEqual(2)->and($resolveQueries)->toBeLessThanOrEqual(1)
        ->and(array_values(array_map(fn (?string $v) => $answers[$v ?? ''], $column)))->toBe($expected)
        ->and($answers['MK005@markedge.test'])->toBe($ids['MK005'])->and($answers['mk999'])->toBeNull()
        ->and($s->report()->totals())->toEqual(['matched' => 7648, 'unmatched' => 588]);
});
