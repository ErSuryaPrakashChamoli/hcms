<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Configuration\Cloning\Adapters\PolicyCloneAdapter;
use App\Domain\Configuration\Cloning\ApplicabilityKind;
use App\Domain\Configuration\Cloning\ApplicabilityRewriter;
use App\Domain\Configuration\Cloning\CloneAdapter;
use App\Domain\Configuration\Cloning\ClonePlan;
use App\Domain\Configuration\Cloning\CloneRefusedException;
use App\Domain\Configuration\Cloning\CloneRequest;
use App\Domain\Configuration\Cloning\ConfigurationCloner;
use App\Domain\Configuration\Cloning\Destination;
use App\Domain\Configuration\Cloning\Materialised;
use App\Domain\Configuration\Cloning\Snapshot;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Models\ConfigurationCloneOperation;
use App\Domain\Configuration\Models\ConfigurationLineage;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Configuration\Services\PolicySetup;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Services\FeatureFlags;
use App\Filament\Resources\ConfigurationCloneOperations\Pages\ListConfigurationCloneOperations;
use App\Filament\Resources\ConfigurationCloneOperations\Pages\ViewConfigurationCloneOperation;
use App\Filament\Resources\Policies\Pages\EditPolicy;
use App\Filament\Resources\Policies\Pages\ListPolicies;
use App\Filament\Support\Cloning\CloneLinks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| Configuration cloning, phase 1 (docs/architecture/configuration-cloning.md): the shared engine with the policy adapter,
| measured against the framework acceptance criteria AC-F1 to AC-F13 of the accepted design.
|
| Markedge (MK) has its own leave policy for full-time staff, with a Delhi rule; FynnEdge (FE) has a Delhi office of the
| same name; Third (TH) has none. Everyone else falls back to the standard policy.
*/

beforeEach(function () {
    $this->travelTo('2026-11-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->cloner = app(ConfigurationCloner::class);

    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->th = Company::factory()->create(['name' => 'Third Labs', 'code' => 'TH']);
    $this->delhiMk = Location::factory()->create(['name' => 'Delhi', 'company_id' => $this->mk->id]);
    $this->delhiFe = Location::factory()->create(['name' => 'Delhi', 'company_id' => $this->fe->id]);
    $this->sales = Department::factory()->create(['name' => 'Sales', 'company_id' => null]);
    $this->fullTime = EmploymentType::factory()->create(['name' => 'Full-time']);

    $policies = app(Policies::class);
    $this->standard = Policy::create(['type' => 'leave', 'name' => 'Standard leave', 'code' => 'STD']);
    $policies->draft($this->standard, ['count_weekly_offs' => false]);
    $policies->publish($this->standard, '2026-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'leave', 'policy_id' => $this->standard->id, 'name' => 'Everyone', 'priority' => 1000, 'conditions' => []]);

    $this->source = Policy::create(['type' => 'leave', 'name' => 'Markedge leave', 'code' => 'MK_LEAVE', 'description' => 'Full-time staff']);
    $policies->draft($this->source, ['count_weekly_offs' => true]);
    $policies->publish($this->source, '2026-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'leave', 'policy_id' => $this->source->id, 'name' => 'Delhi office', 'priority' => 50, 'conditions' => [
        ['field' => 'company_id', 'operator' => 'equals', 'value' => $this->mk->id],
        ['field' => 'location_id', 'operator' => 'in', 'value' => [$this->delhiMk->id]],
    ]]);
    PolicyAssignmentRule::create(['policy_type' => 'leave', 'policy_id' => $this->source->id, 'name' => 'Full-time', 'priority' => 100, 'conditions' => [
        ['field' => 'company_id', 'operator' => 'in', 'value' => [$this->mk->id]],
        ['field' => 'employment_type_id', 'operator' => 'in', 'value' => [$this->fullTime->id]],
    ]]);

    $this->hire = fn (Company $company, ?Location $location = null) => app(HireEmployeeAction::class)->handle(
        ['first_name' => fake()->firstName(), 'last_name' => 'Clone'],
        ['joining_date' => '2025-01-01'],
        ['company_id' => $company->id, 'location_id' => $location?->id, 'department_id' => $this->sales->id, 'employment_type_id' => $this->fullTime->id],
    );
    $this->request = fn (array $destinations, array $extra = []) => new CloneRequest(...($extra + [
        'adapter' => 'policy', 'sourceId' => $this->source->id, 'destinations' => $destinations, 'reason' => 'Second entity', 'acknowledged' => true,
    ]));
});

/** Every row of a policy, its versions and its rules, timestamps included (AC-F1). */
function policyRows(Policy $policy): array
{
    return [
        DB::table('policies')->where('id', $policy->id)->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('policy_versions')->where('policy_id', $policy->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('policy_assignment_rules')->where('policy_id', $policy->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
}

/** A user holding exactly these keys, through a role limited to one company when given. */
function cloneUser(array $keys, ?int $companyId = null): User
{
    $user = User::factory()->forTenant(test()->tenant)->create();
    $role = Role::factory()->create();
    $role->permissions()->sync(app(PermissionRegistry::class)->idsMatching($keys));
    $user->roles()->attach($role, ['company_id' => $companyId]);

    return $user->refresh();
}

it('clones into several companies at once: independent inactive copies bounded to each company, source untouched (AC-F1 to AC-F4)', function () {
    $feDelhi = ($this->hire)($this->fe, $this->delhiFe);
    $feOther = ($this->hire)($this->fe);
    $before = policyRows($this->source);
    $resolved = fn () => [app(PolicyResolver::class)->resolve('leave', $feDelhi)?->policy?->code, app(PolicyResolver::class)->resolve('leave', $feOther)?->policy?->code];
    $resolvedBefore = $resolved();

    $result = $this->cloner->execute(($this->request)([
        new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE'),
        new Destination($this->th->id, 'Third leave', 'MK_LEAVE_TH', 80),
    ], ['unitChoices' => [$this->th->id => ['location_id' => [$this->delhiMk->id => CloneRequest::DROP]]]]), $this->admin);

    [$fe, $th] = $result->copies;
    expect($result->completed())->toBeTrue()
        ->and(count($result->copies))->toBe(2)
        ->and([$fe->code, $th->code])->toBe(['MK_LEAVE_FE', 'MK_LEAVE_TH'])
        ->and($fe->type)->toBe('leave')->and($fe->description)->toBe('Full-time staff')
        // Settings of the version in force, as a draft v1 (OD-5); no published version, so it resolves for nobody.
        ->and($fe->versions()->sole()->only(['version', 'status', 'settings']))->toBe(['version' => 1, 'status' => VersionStatus::Draft, 'settings' => ['count_weekly_offs' => true]])
        // AC-F1: the source is byte-identical, updated_at included.
        ->and(policyRows($this->source->fresh()))->toBe($before)
        // AC-F2: until activated, every destination employee resolves exactly as before.
        ->and($resolved())->toBe($resolvedBefore);

    // AC-F3: every rule is "all of", starts with the destination company only, keeps shared values exactly and maps units.
    // toEqual, not toBe: MySQL keeps JSON object keys in its own order (field, value, operator); rules and order still match.
    $feRules = $fe->assignmentRules()->orderBy('priority')->get();
    expect($feRules->pluck('status')->unique()->all())->toBe([ActiveStatus::Inactive])
        ->and($feRules->pluck('name')->all())->toBe(['Delhi office', 'Full-time'])
        ->and($feRules[0]->conditions)->toEqual([
            ['field' => 'company_id', 'operator' => 'in', 'value' => [$this->fe->id]],
            ['field' => 'location_id', 'operator' => 'in', 'value' => [$this->delhiFe->id]],
        ])
        ->and($feRules[1]->conditions)->toEqual([
            ['field' => 'company_id', 'operator' => 'in', 'value' => [$this->fe->id]],
            ['field' => 'employment_type_id', 'operator' => 'in', 'value' => [$this->fullTime->id]],
        ]);
    // Third had no Delhi: the location was removed by choice (the rule then reaches all of Third) and the priority given was used.
    expect($th->assignmentRules()->orderBy('id')->get()->map(fn ($r) => [$r->priority, $r->conditions])->all())->toEqual([
        [80, [['field' => 'company_id', 'operator' => 'in', 'value' => [$this->th->id]]]],
        [80, [['field' => 'company_id', 'operator' => 'in', 'value' => [$this->th->id]], ['field' => 'employment_type_id', 'operator' => 'in', 'value' => [$this->fullTime->id]]]],
    ]);
    // No copy names the source company anywhere.
    $copyRules = PolicyAssignmentRule::query()->whereIn('policy_id', [$fe->id, $th->id])->get();
    expect($copyRules->flatMap(fn ($r) => collect($r->conditions)->where('field', 'company_id')->flatMap(fn ($c) => (array) $c['value']))->contains($this->mk->id))->toBeFalse();

    // AC-F4: two roots with distinct codes; lineage for the copies, their rules, reused records and mapped units.
    $operation = $result->operation;
    expect($operation->status)->toBe(ConfigurationCloneOperation::COMPLETED)
        ->and($operation->mode)->toBe('companies')
        ->and($operation->source_company_ids)->toBe([$this->mk->id])
        ->and($operation->lineage()->where('role', 'root')->pluck('target_id')->all())->toBe([$fe->id, $th->id])
        ->and($operation->lineage()->where('adapter', 'policy.rule')->count())->toBe(4)
        ->and($operation->lineage()->where('action', 'mapped')->get(['adapter', 'source_id', 'target_id'])->toArray())->toBe([['adapter' => 'location_id', 'source_id' => $this->delhiMk->id, 'target_id' => $this->delhiFe->id]]);
});

it('records one audited operation linking every created row, with the manifest, and keeps the audit chain valid (AC-F12)', function () {
    $result = $this->cloner->execute(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE')]), $this->admin);
    $operationId = $result->operation->audit_operation_id;

    $clone = AuditEvent::query()->where('action', AuditAction::Clone->value)->sole();
    expect($operationId)->not->toBeNull()
        ->and($clone->operation_id)->toBe($operationId)
        ->and($clone->metadata['status'])->toBe('completed')
        ->and($clone->metadata['mode'])->toBe('company')
        ->and($clone->metadata['source_companies'])->toBe([$this->mk->id])
        ->and($clone->metadata['created'])->toBe([$this->fe->id => $result->copies[0]->id])
        ->and($clone->metadata['applicability'][$this->fe->id]['after']['rules'])->toHaveCount(2)
        ->and(AuditEvent::query()->where('action', AuditAction::Create->value)->where('operation_id', $operationId)->pluck('entity_type')->unique()->values()->all())
        ->toContain(Policy::class, PolicyVersion::class, PolicyAssignmentRule::class)
        ->and(AuditEvent::query()->where('action', AuditAction::Create->value)->where('operation_id', $operationId)->where('entity_type', Policy::class)->sole()->reason)->toBe('Cloned from Markedge leave (MK_LEAVE): Second entity')
        ->and(AuditEvent::query()->where('action', AuditAction::BulkOperation->value)->where('operation_id', $operationId)->sole()->metadata['success_count'])->toBe(1)
        ->and(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue();
});

it('turns the copy on with its first publish, then lets the copy win where its priority does (activation, FR-7)', function () {
    $employee = ($this->hire)($this->fe, $this->delhiFe);
    $copy = $this->cloner->execute(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE')]), $this->admin)->copies[0];

    expect(app(Policies::class)->awaitsCloneActivation($copy))->toBeTrue()
        ->and(app(PolicyResolver::class)->resolve('leave', $employee)->policy->code)->toBe('STD');

    // The policy wizard edits the copy's inactive rules as its own, keeps them inactive on a draft save, and publishing turns them on.
    $setup = app(PolicySetup::class);
    expect($setup->manageable($copy))->toBeFalse(); // two group rules: the rule editor keeps them, as for any policy
    app(Policies::class)->publish($copy, '2026-11-21', 'Go live in FynnEdge');

    expect($copy->assignmentRules()->pluck('status')->unique()->all())->toBe([ActiveStatus::Active])
        ->and(app(Policies::class)->awaitsCloneActivation($copy->fresh()))->toBeFalse()
        ->and(app(PolicyResolver::class)->resolve('leave', $employee)->policy->code)->toBe('MK_LEAVE_FE')
        // The source's own people are untouched.
        ->and(app(PolicyResolver::class)->resolve('leave', ($this->hire)($this->mk, $this->delhiMk))->policy->code)->toBe('MK_LEAVE');
});

it('lets the policy wizard finish a copy with one plain rule: rules stay inactive on draft and come on with publish', function () {
    $this->source->assignmentRules()->where('name', 'Delhi office')->delete();
    $copy = $this->cloner->execute(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE')]), $this->admin)->copies[0];
    $setup = app(PolicySetup::class);
    $state = $setup->load($copy);

    expect($setup->manageable($copy))->toBeTrue()
        ->and($state['applies_to'])->toBe('groups')
        ->and($state['applicability'])->toBe(['company_id' => [$this->fe->id], 'employment_type_id' => [$this->fullTime->id]]);

    $state['employees'] = [($this->hire)($this->fe)->id];
    $setup->save($state, $copy, $this->admin, false, 'Named one more');
    expect($copy->assignmentRules()->pluck('status')->unique()->all())->toBe([ActiveStatus::Inactive]);

    $setup->save($state, $copy->fresh(), $this->admin, true, 'Publish');
    expect($copy->assignmentRules()->count())->toBe(2)
        ->and($copy->assignmentRules()->pluck('status')->unique()->all())->toBe([ActiveStatus::Active]);
});

it('never propagates: later changes to the source leave the copy as it was, and Compare with source shows them (AC-F13)', function () {
    $copy = $this->cloner->execute(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE')]), $this->admin)->copies[0];
    app(Policies::class)->draft($this->source, ['count_weekly_offs' => false]);
    app(Policies::class)->publish($this->source, '2026-11-22', 'Changed later');
    $this->travelTo('2026-11-23 10:00:00');

    expect($copy->draft()->sole()->settings)->toBe(['count_weekly_offs' => true]);

    $compare = CloneLinks::compare('policy', $copy);
    $row = collect($compare['rows'])->first(fn (array $r) => str_contains($r['label'], 'weekly offs'));
    expect($compare['intro'])->toContain('Copied from Markedge leave (MK_LEAVE)')
        ->and($row['then'])->toBe($row['copy'])
        ->and($row['source'])->not->toBe($row['then'])
        ->and($row['changed'])->toBeTrue();

    Livewire::test(EditPolicy::class, ['record' => $copy->getRouteKey()])->assertOk()->assertSee('Copied from Markedge leave (MK_LEAVE)');
    Livewire::test(EditPolicy::class, ['record' => $this->source->getRouteKey()])->assertOk()->assertSee('Copied 1 time(s): MK_LEAVE_FE (FynnEdge Advisory)');
});

it('bounds every rewritten rule to the destination over random condition sets (AC-F3 property)', function () {
    $rewriter = app(ApplicabilityRewriter::class);
    $all = [$this->mk->id, $this->fe->id, $this->th->id];
    mt_srand(20261121);
    $fields = [
        fn () => ['field' => 'company_id', 'operator' => ['equals', 'in', 'not_in', 'not_equals'][mt_rand(0, 3)], 'value' => mt_rand(0, 1) ? $this->mk->id : [$this->mk->id, $this->th->id]],
        fn () => ['field' => 'employment_type_id', 'operator' => 'in', 'value' => [$this->fullTime->id]],
        fn () => ['field' => 'grade_id', 'operator' => 'not_in', 'value' => [mt_rand(1, 9)]],
        fn () => ['field' => 'tenure_months', 'operator' => 'gte', 'value' => mt_rand(0, 24)],
        fn () => ['field' => 'location_id', 'operator' => 'in', 'value' => [$this->delhiMk->id]],
        fn () => ['field' => 'department_id', 'operator' => 'in', 'value' => [$this->sales->id]],
    ];
    for ($i = 0; $i < 200; $i++) {
        $rules = [];
        for ($r = 0, $n = mt_rand(0, 3); $r < $n; $r++) {
            $conditions = [];
            for ($c = 0, $m = mt_rand(0, 4); $c < $m; $c++) {
                $conditions[] = $fields[mt_rand(0, count($fields) - 1)]();
            }
            $rules[] = ['id' => $r + 1, 'name' => "R{$r}", 'priority' => 10 * ($r + 1), 'match' => mt_rand(0, 3) ? 'all' : 'any', 'conditions' => $conditions];
        }
        $destination = [$this->fe->id, $this->th->id][mt_rand(0, 1)];
        $out = $rewriter->rewrite(ApplicabilityKind::RuleConditions, ['rules' => $rules], $destination, false, [], null, $all);
        if ($out['blockers'] !== []) {
            continue; // refused, never written
        }
        $rewriter->assertBounded(ApplicabilityKind::RuleConditions, $out['after'], [$destination]);
        foreach ($out['after']['rules'] as $rule) {
            $company = collect($rule['conditions'])->where('field', 'company_id')->values();
            expect($rule['match'])->toBe('all')
                ->and($company)->toHaveCount(1)
                ->and($company[0]['value'])->toBe([$destination])
                ->and(collect($rule['conditions'])->where('field', 'location_id')->flatMap(fn ($c) => (array) $c['value'])->all())->not->toContain($this->delhiMk->id)
                ->and(collect($rule['conditions'])->whereIn('field', ['employment_type_id', 'grade_id', 'tenure_months'])->values()->all())
                ->toBe(collect(collect($rules)->firstWhere('name', $rule['name'])['conditions'])->whereIn('field', ['employment_type_id', 'grade_id', 'tenure_months'])->values()->all());
        }
    }
});

it('never copies a policy as "everyone", refuses "any of" rules, and does not copy rules naming employees (FR-13 to FR-15)', function () {
    $rewriter = app(ApplicabilityRewriter::class);
    $kind = ApplicabilityKind::RuleConditions;
    $all = [$this->mk->id, $this->fe->id];

    $everyone = $rewriter->rewrite($kind, ['rules' => [['name' => 'All', 'priority' => 1000, 'conditions' => []]]], $this->fe->id, true, [], null, $all);
    $any = $rewriter->rewrite($kind, ['rules' => [['name' => 'Either', 'match' => 'any', 'conditions' => [
        ['field' => 'company_id', 'operator' => 'equals', 'value' => $this->mk->id], ['field' => 'grade_id', 'operator' => 'in', 'value' => [1]],
    ]]]], $this->fe->id, false, [], null, $all);
    $named = $rewriter->rewrite($kind, ['rules' => [['name' => 'Named', 'conditions' => [['field' => 'employee_id', 'operator' => 'in', 'value' => [5]]]]]], $this->fe->id, false, [], null, $all);
    $unknown = $rewriter->rewrite($kind, ['rules' => [['name' => 'Odd', 'conditions' => [['field' => 'cost_centre_id', 'operator' => 'in', 'value' => [5]]]]]], $this->fe->id, false, [], null, $all);
    $variation = $rewriter->rewrite($kind, ['rules' => [['name' => 'Third only', 'conditions' => [['field' => 'company_id', 'operator' => 'in', 'value' => [$this->th->id]]]]]], $this->fe->id, true, [], null, $all);

    expect($everyone['after']['rules'][0]['conditions'])->toBe([['field' => 'company_id', 'operator' => 'in', 'value' => [$this->fe->id]]])
        ->and($any['blockers'][0]->code)->toBe('any_mode')
        ->and($named['after']['rules'])->toBe([])->and($named['notes'][0])->toContain('names employees')
        ->and($unknown['blockers'][0]->code)->toBe('unclassified_condition')
        ->and($variation['after']['rules'])->toBe([])->and($variation['notes'][0])->toContain('does not apply in this company')
        ->and(fn () => $rewriter->assertBounded($kind, ['rules' => [['match' => 'all', 'conditions' => []]]], [$this->fe->id]))->toThrow(CloneRefusedException::class)
        ->and(fn () => $rewriter->assertBounded($kind, ['rules' => [['match' => 'all', 'conditions' => [['field' => 'company_id', 'operator' => 'in', 'value' => [$this->mk->id]]]]]], [$this->fe->id]))->toThrow(CloneRefusedException::class)
        // A column or a list of companies: the destination, never "every company".
        ->and($rewriter->rewrite(ApplicabilityKind::CompanyColumn, ['company_id' => null], $this->fe->id, true, [], null, $all)['after'])->toBe(['company_id' => $this->fe->id])
        ->and($rewriter->rewrite(ApplicabilityKind::CompanyIdList, ['company_ids' => [$this->mk->id]], $this->fe->id, false, [], null, $all)['after'])->toBe(['company_ids' => [$this->fe->id]]);
});

it('blocks a unit with no equivalent until it is mapped or removed, and suggests the same-named one (FR-11, FR-14)', function () {
    $pune = Location::factory()->create(['name' => 'Pune', 'company_id' => $this->mk->id]);
    $rule = $this->source->assignmentRules()->where('name', 'Delhi office')->sole();
    $rule->update(['conditions' => [['field' => 'company_id', 'operator' => 'equals', 'value' => $this->mk->id], ['field' => 'location_id', 'operator' => 'in', 'value' => [$this->delhiMk->id, $pune->id]]]]);
    $puneFe = Location::factory()->create(['name' => 'Pune West', 'company_id' => $this->fe->id]);

    $plan = $this->cloner->plan(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE')]), $this->admin);
    expect(collect($plan->blockers())->pluck('code')->all())->toBe(['unmapped_unit'])
        ->and($plan->blockers()[0]->message)->toContain('"Pune"')
        ->and(fn () => $this->cloner->execute(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE')]), $this->admin))->toThrow(CloneRefusedException::class, 'Pune');

    $mapped = $this->cloner->plan(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE')], ['unitChoices' => [$this->fe->id => ['location_id' => [$pune->id => $puneFe->id]]]]), $this->admin);
    expect($mapped->blockers())->toBe([])
        ->and($mapped->rulesFor($this->fe->id)[0]['conditions'][1])->toBe(['field' => 'location_id', 'operator' => 'in', 'value' => [$this->delhiFe->id, $puneFe->id]])
        // A choice may not point at another company's unit.
        ->and($this->cloner->plan(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE')], ['unitChoices' => [$this->fe->id => ['location_id' => [$pune->id => $pune->id]]]]), $this->admin)->blockers())->not->toBe([]);
});

it('reports conflicts: codes in use or repeated are blockers, outranked copies and look-alikes are warnings to acknowledge (AC-F7)', function () {
    ($this->hire)($this->fe, $this->delhiFe);
    $plan = $this->cloner->plan(($this->request)([
        new Destination($this->fe->id, 'Standard leave', 'std'),
        new Destination($this->th->id, 'Third leave', 'STD'),
    ], ['unitChoices' => [$this->th->id => ['location_id' => [$this->delhiMk->id => CloneRequest::DROP]]]]), $this->admin);

    expect(collect($plan->blockers())->pluck('code')->all())->toBe(['code_in_use', 'code_repeated'])
        ->and(collect($plan->warnings())->pluck('code')->all())->toContain('look_alike');

    // The FynnEdge copy at priority 2000 would lose to the standard policy (priority 1000) for the one person it covers.
    $outranked = $this->cloner->plan(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE', 2000)], ['acknowledged' => false]), $this->admin);
    expect(collect($outranked->warnings())->pluck('code')->all())->toBe(['outranked'])
        ->and($outranked->warnings()[0]->message)->toContain('would keep "Standard leave" (priority 1000)')
        ->and($outranked->impact[$this->fe->id])->toMatchArray(['employees' => 1, 'matched' => 1, 'winning' => 0])
        // The impact count builds rule contexts in bulk: exactly what the resolver builds one by one.
        ->and(app(EmployeeRuleContext::class)->buildMany(Employee::query()->with('person')->get()))
        ->toBe(Employee::query()->with('person')->get()->mapWithKeys(fn (Employee $e) => [$e->id => app(EmployeeRuleContext::class)->build($e)])->all())
        ->and(fn () => $this->cloner->execute(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE', 2000)], ['acknowledged' => false]), $this->admin))
        ->toThrow(CloneRefusedException::class, 'tick that you have')
        ->and(ConfigurationCloneOperation::query()->where('status', 'refused')->count())->toBe(1)
        ->and(Policy::query()->where('code', 'MK_LEAVE_FE')->exists())->toBeFalse();
});

it('turns a code taken while the copies are written into a conflict, never a server error, and leaves nothing (AC-F7, AC-F8)', function () {
    config(['peopleos.cloning.adapters.policy' => CodeThiefPolicyAdapter::class]);
    $counts = fn () => [Policy::query()->count(), PolicyVersion::query()->count(), PolicyAssignmentRule::query()->count(), ConfigurationLineage::query()->count()];
    $before = $counts();

    expect(fn () => $this->cloner->execute(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE')], ['idempotencyKey' => 'k-thief']), $this->admin))
        ->toThrow(CloneRefusedException::class, 'took one of these codes');

    // The parallel write and the copy were rolled back together: nothing is left behind.
    $operation = ConfigurationCloneOperation::query()->sole();
    expect($counts())->toBe($before)
        ->and($operation->status)->toBe('failed')
        ->and($operation->idempotency_key)->toBeNull();
});

it('is all or nothing: a failure on the second of three companies leaves no copy, a failed record and its audit summary (AC-F8)', function () {
    config(['peopleos.cloning.adapters.policy' => FailingSecondPolicyAdapter::class]);
    FailingSecondPolicyAdapter::$calls = 0;
    $counts = fn () => [Policy::query()->count(), PolicyVersion::query()->count(), PolicyAssignmentRule::query()->count(), ConfigurationLineage::query()->count()];
    $before = $counts();
    $other = Company::factory()->create(['name' => 'Other', 'code' => 'OT']);

    expect(fn () => $this->cloner->execute(($this->request)([
        new Destination($this->fe->id, 'A', 'COPY_A'), new Destination($other->id, 'B', 'COPY_B'), new Destination($this->th->id, 'C', 'COPY_C'),
    ], ['idempotencyKey' => 'k-fail', 'unitChoices' => [$other->id => ['location_id' => [$this->delhiMk->id => 'drop']], $this->th->id => ['location_id' => [$this->delhiMk->id => 'drop']]]]), $this->admin))
        ->toThrow(RuntimeException::class, 'Disk full');

    $operation = ConfigurationCloneOperation::query()->sole();
    expect($counts())->toBe($before)
        ->and($operation->status)->toBe('failed')->and($operation->error)->toBe('Disk full')
        ->and($operation->idempotency_key)->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::BulkOperation->value)->where('operation_id', $operation->audit_operation_id)->sole()->metadata['error'])->toBe('Disk full')
        ->and(AuditEvent::query()->where('action', AuditAction::Create->value)->where('entity_type', Policy::class)->where('operation_id', $operation->audit_operation_id)->exists())->toBeFalse();

    // The same wizard may try again once fixed: the key was freed.
    config(['peopleos.cloning.adapters.policy' => PolicyCloneAdapter::class]);
    expect($this->cloner->execute(($this->request)([new Destination($this->fe->id, 'A', 'COPY_A')], ['idempotencyKey' => 'k-fail']), $this->admin)->completed())->toBeTrue();
});

it('makes a clone once per wizard: the same key returns the first result (AC-F9)', function () {
    $request = ($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE')], ['idempotencyKey' => (string) Str::ulid()]);
    $first = $this->cloner->execute($request, $this->admin);
    $again = $this->cloner->execute($request, $this->admin);

    expect($again->repeated)->toBeTrue()
        ->and($again->operation->id)->toBe($first->operation->id)
        ->and($again->copies[0]->id)->toBe($first->copies[0]->id)
        ->and(ConfigurationCloneOperation::query()->count())->toBe(1)
        ->and(Policy::query()->where('code', 'MK_LEAVE_FE')->count())->toBe(1);
});

it('refuses when the source changed after it was reviewed (checksum) and when its own data is unchanged otherwise', function () {
    $adapter = app(PolicyCloneAdapter::class);
    $reviewed = $adapter->snapshot($this->source->fresh())->checksum();
    expect($adapter->snapshot($this->source->fresh())->checksum())->toBe($reviewed);

    $this->source->assignmentRules()->where('name', 'Full-time')->sole()->update(['priority' => 90]);
    expect(fn () => $this->cloner->execute(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE')], ['expectedChecksum' => $reviewed]), $this->admin))
        ->toThrow(CloneRefusedException::class, 'changed since you started');
});

it('stays inside the tenant: another tenant\'s source or company is never found (AC-F10)', function () {
    $other = provisionTenant('Other');
    $foreign = app(TenantContext::class)->runAs($other, fn () => [
        'policy' => Policy::create(['type' => 'leave', 'name' => 'Theirs', 'code' => 'THEIRS']),
        'company' => Company::factory()->create(['code' => 'OC']),
    ]);
    actAsTenant($this->tenant);

    expect(fn () => $this->cloner->execute(new CloneRequest('policy', $foreign['policy']->id, [new Destination($this->fe->id, 'X', 'X1')], reason: 'x', acknowledged: true), $this->admin))
        ->toThrow(CloneRefusedException::class, 'no longer exists')
        ->and(fn () => $this->cloner->execute(($this->request)([new Destination($foreign['company']->id, 'X', 'X2')]), $this->admin))
        ->toThrow(CloneRefusedException::class, 'does not exist')
        ->and(app(TenantContext::class)->runAs($other, fn () => Policy::query()->whereIn('code', ['X1', 'X2'])->count()))->toBe(0)
        ->and(Policy::query()->whereIn('code', ['X1', 'X2'])->count())->toBe(0);
});

it('enforces permissions and company scope in the service and the screens (AC-F11)', function () {
    $variationOnly = cloneUser(['policy.create', 'policy.view', 'configuration.clone']);
    $cross = cloneUser(['policy.create', 'policy.view', 'configuration.clone', 'configuration.clone.cross_company']);
    $noClone = cloneUser(['policy.create', 'policy.view']);
    $feOnlyRole = cloneUser(['policy.create', 'policy.view', 'configuration.clone', 'configuration.clone.cross_company'], $this->fe->id);
    $scoped = cloneUser(['policy.create', 'policy.view', 'configuration.clone', 'configuration.clone.cross_company']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$this->fe->id]], 'FynnEdge HR');
    $fe = fn (string $code) => ($this->request)([new Destination($this->fe->id, 'FynnEdge leave', $code)]);

    expect(fn () => $this->cloner->execute($fe('A1'), $noClone))->toThrow(CloneRefusedException::class, 'do not let you clone policies')
        ->and(fn () => $this->cloner->execute($fe('A2'), $variationOnly))->toThrow(CloneRefusedException::class, 'only as a variation')
        ->and($this->cloner->execute(($this->request)([new Destination($this->mk->id, 'Markedge leave (copy)', 'A3')]), $variationOnly)->completed())->toBeTrue()
        ->and($this->cloner->execute($fe('A4'), $cross)->completed())->toBeTrue()
        // A role limited to FynnEdge creates for FynnEdge only (company-aware permission check).
        ->and($this->cloner->execute($fe('A5'), $feOnlyRole)->completed())->toBeTrue()
        ->and(fn () => $this->cloner->execute(($this->request)([new Destination($this->th->id, 'T', 'A6')], ['unitChoices' => [$this->th->id => ['location_id' => [$this->delhiMk->id => 'drop']]]]), $feOnlyRole))
        ->toThrow(CloneRefusedException::class, 'do not let you create policies for Third Labs')
        // A person scoped to FynnEdge cannot clone a source aimed at Markedge (OD-4)…
        ->and(fn () => $this->cloner->execute($fe('A7'), $scoped))->toThrow(CloneRefusedException::class, 'outside your access');

    // …but may clone one that applies to every company, into their own companies only.
    $everyone = new CloneRequest('policy', $this->standard->id, [new Destination($this->fe->id, 'FynnEdge standard', 'STD_FE')], reason: 'x', acknowledged: true);
    expect($this->cloner->execute($everyone, $scoped)->completed())->toBeTrue()
        ->and(fn () => $this->cloner->execute(new CloneRequest('policy', $this->standard->id, [new Destination($this->mk->id, 'M', 'STD_MK')], reason: 'x', acknowledged: true), $scoped))
        ->toThrow(CloneRefusedException::class, 'outside your access');

    // Screens: the action shows only with configuration.clone and the type's create permission.
    $this->actingAs($noClone);
    Livewire::test(ListPolicies::class)->assertTableActionHidden('clone', $this->source);
    $this->actingAs($cross);
    Livewire::test(ListPolicies::class)->assertTableActionVisible('clone', $this->source);

    // Switched off for the tenant: hidden and refused.
    app(FeatureFlags::class)->set('configuration.cloning', false, 'Not yet');
    Livewire::test(ListPolicies::class)->assertTableActionHidden('clone', $this->source);
    expect(fn () => app(ConfigurationCloner::class)->execute($fe('A8'), $cross))->toThrow(CloneRefusedException::class, 'switched off');
});

it('never writes transactional rows and copies only the chosen version (AC-F6)', function () {
    $tables = app(PolicyCloneAdapter::class)->excluded();
    $before = collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
    app(Policies::class)->draft($this->source, ['count_weekly_offs' => false], 'Next year');
    $v1 = $this->source->versions()->where('version', 1)->sole();

    $copy = $this->cloner->execute(($this->request)([new Destination($this->fe->id, 'FynnEdge leave', 'MK_LEAVE_FE')], ['options' => ['version_id' => $this->source->draft()->sole()->id]]), $this->admin)->copies[0];

    expect(collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all())->toBe($before)
        ->and($copy->versions()->count())->toBe(1)
        ->and($copy->draft()->sole()->settings)->toBe(['count_weekly_offs' => false])
        ->and(app(PolicyCloneAdapter::class)->sourceOptions($this->source)['version_id']['default'])->toBe($v1->id);
});

it('classifies every policy settings source and every rule field, so no reference is carried over unexamined (classification guard)', function () {
    $sources = [];
    $walk = function (array $fields) use (&$walk, &$sources) {
        foreach ($fields as $field) {
            if (isset($field['options_from'])) {
                $sources[] = $field['options_from'];
            }
            $walk((array) ($field['fields'] ?? []));
        }
    };
    foreach ((array) config('peopleos.policies.types') as $type) {
        $walk((array) ($type['fields'] ?? []));
    }
    $classified = [...array_keys(PolicyCloneAdapter::OPTION_SOURCES), ...PolicyCloneAdapter::STATIC_SOURCES];

    expect(array_values(array_diff(array_unique($sources), $classified)))->toBe([])
        ->and(collect(array_keys((array) config('peopleos.rules.fields')))->reject(fn (string $f) => ApplicabilityRewriter::classify($f) !== null)->values()->all())->toBe([])
        ->and(collect((array) config('peopleos.cloning.adapters'))->every(fn (string $class) => is_a($class, CloneAdapter::class, true)))->toBeTrue();
});

it('lists every setting the copy points at as reused, and flags ones that are gone', function () {
    $letter = LetterTemplate::query()->create(['name' => 'Exit letter', 'code' => 'EXIT_L', 'type' => 'relieving', 'subject' => 'Exit', 'body' => 'x', 'status' => 'active']);
    $exit = Policy::create(['type' => 'exit', 'name' => 'Exit', 'code' => 'EXIT']);
    app(Policies::class)->draft($exit, ['letter_on_exit' => $letter->id, 'letter_on_completion' => 999999]);
    $adapter = app(PolicyCloneAdapter::class);
    $references = $adapter->references($adapter->snapshot($exit));

    expect(collect($references)->map(fn ($r) => [$r->path, $r->type, $r->targetId, $r->strategy->value, $r->problem !== null])->all())->toBe([
        ['settings.letter_on_exit', 'letter_templates', $letter->id, 'reuse', false],
        ['settings.letter_on_completion', 'letter_templates', null, 'reuse', true],
    ]);
});

it('clones from the policy list as a wizard, and shows the clone in Clone history', function () {
    Livewire::test(ListPolicies::class)
        ->callTableAction('clone', $this->source, [
            'destinations' => [['company_id' => $this->fe->id, 'name' => 'FynnEdge leave', 'code' => 'MK_LEAVE_FE', 'priority' => null]],
            'acknowledge' => true,
            'audit_reason' => 'Second entity',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $copy = Policy::query()->where('code', 'MK_LEAVE_FE')->sole();
    $operation = ConfigurationCloneOperation::query()->sole();
    expect($copy->assignmentRules()->pluck('status')->unique()->all())->toBe([ActiveStatus::Inactive])
        ->and($operation->idempotency_key)->not->toBeNull()
        ->and($operation->status)->toBe('completed');

    Livewire::test(ListConfigurationCloneOperations::class)->assertOk()->assertCanSeeTableRecords([$operation])->assertSee('FynnEdge Advisory');
    Livewire::test(ViewConfigurationCloneOperation::class, ['record' => $operation->getRouteKey()])->assertOk()->assertSee('Markedge leave (MK_LEAVE)');
    Livewire::test(EditPolicy::class, ['record' => $copy->getRouteKey()])->assertOk()->assertActionVisible('compareWithSource');
});

/** Takes the copy's code just before the copy is saved, as a parallel clone would. */
class CodeThiefPolicyAdapter implements CloneAdapter
{
    public function __construct(private readonly PolicyCloneAdapter $inner) {}

    public function materialise(Snapshot $snapshot, Destination $destination, ClonePlan $plan, User $actor): Materialised
    {
        DB::transaction(fn () => Policy::create(['type' => 'leave', 'name' => 'Thief', 'code' => $destination->code]));

        return $this->inner->materialise($snapshot, $destination, $plan, $actor);
    }

    public function key(): string
    {
        return $this->inner->key();
    }

    public function label(): string
    {
        return $this->inner->label();
    }

    public function model(): string
    {
        return $this->inner->model();
    }

    public function createPermission(): string
    {
        return $this->inner->createPermission();
    }

    public function applicability(): ApplicabilityKind
    {
        return $this->inner->applicability();
    }

    public function find(int $id): ?Model
    {
        return $this->inner->find($id);
    }

    public function sourceOptions(Model $source): array
    {
        return $this->inner->sourceOptions($source);
    }

    public function snapshot(Model $source, array $options = []): Snapshot
    {
        return $this->inner->snapshot($source, $options);
    }

    public function references(Snapshot $snapshot): array
    {
        return $this->inner->references($snapshot);
    }

    public function impact(Snapshot $snapshot, Destination $destination, ClonePlan $plan): ?array
    {
        return $this->inner->impact($snapshot, $destination, $plan);
    }

    public function conflicts(Snapshot $snapshot, Destination $destination, ClonePlan $plan): array
    {
        return $this->inner->conflicts($snapshot, $destination, $plan);
    }

    public function codeTaken(string $code): bool
    {
        return $this->inner->codeTaken($code);
    }

    public function lookAlikes(Snapshot $snapshot, Destination $destination): array
    {
        return $this->inner->lookAlikes($snapshot, $destination);
    }

    public function excluded(): array
    {
        return $this->inner->excluded();
    }
}

/** Fails while writing the second destination (fault injection). */
class FailingSecondPolicyAdapter extends CodeThiefPolicyAdapter
{
    public static int $calls = 0;

    public function __construct(private readonly PolicyCloneAdapter $policies)
    {
        parent::__construct($policies);
    }

    public function materialise(Snapshot $snapshot, Destination $destination, ClonePlan $plan, User $actor): Materialised
    {
        if (++self::$calls === 2) {
            throw new RuntimeException('Disk full');
        }

        return $this->policies->materialise($snapshot, $destination, $plan, $actor);
    }
}
