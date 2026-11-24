<?php

use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Cloning\Adapters\GenericCloneAdapter;
use App\Domain\Configuration\Cloning\CloneRefusedException;
use App\Domain\Configuration\Cloning\CloneRegistry;
use App\Domain\Configuration\Cloning\CloneRequest;
use App\Domain\Configuration\Cloning\ConfigurationCloner;
use App\Domain\Configuration\Cloning\Destination;
use App\Domain\Configuration\Models\ConfigurationLineage;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Models\OnboardingTemplateItem;
use App\Domain\Onboarding\Services\Onboarding;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Cloning through the framework (acceptance 13.3, Part 7, Part 17 "Clone"): every registered type with a 'clone' key is
| cloned by the shared engine with GenericCloneAdapter, as an independent inactive copy under a new code, for the chosen
| company, with its owned children; the original never changes and later changes never reach the copy.
*/

beforeEach(function () {
    $this->travelTo('2026-11-23 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->cloner = app(ConfigurationCloner::class);
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->clone = fn (string $adapter, int $id, array $destinations, array $extra = []) => $this->cloner->execute(new CloneRequest(...($extra + [
        'adapter' => $adapter, 'sourceId' => $id, 'destinations' => $destinations, 'reason' => 'Second entity', 'acknowledged' => true,
    ])), $this->admin);
});

it('clones a Markedge department for FynnEdge: inactive, owned by FynnEdge, units mapped, original untouched', function () {
    $mkCc = CostCentre::factory()->create(['name' => 'People costs', 'code' => 'MK_PC', 'company_id' => $this->mk->id]);
    $feCc = CostCentre::factory()->create(['name' => 'People costs', 'code' => 'FE_PC', 'company_id' => $this->fe->id]);
    $source = Department::factory()->create(['name' => 'Human Resources', 'code' => 'HR', 'company_id' => $this->mk->id, 'cost_centre_id' => $mkCc->id, 'description' => 'People team']);
    $before = DB::table('departments')->where('id', $source->id)->first();

    $result = ($this->clone)('department', $source->id, [new Destination($this->fe->id, 'Human Resources', 'HR_FE')]);
    $copy = $result->copies[0];

    expect($copy->fresh()->only(['name', 'code', 'company_id', 'description', 'cost_centre_id']))
        ->toBe(['name' => 'Human Resources', 'code' => 'HR_FE', 'company_id' => $this->fe->id, 'description' => 'People team', 'cost_centre_id' => $feCc->id])
        ->and($copy->fresh()->status->value)->toBe('inactive')
        ->and((array) DB::table('departments')->where('id', $source->id)->first())->toBe((array) $before)
        ->and(app(EntityApplicability::class)->reach($copy->fresh()))->toBe([$this->fe->id])
        ->and($result->operation->lineage()->where('role', 'root')->sole()->only(['source_id', 'target_id']))->toBe(['source_id' => $source->id, 'target_id' => $copy->id])
        ->and($result->operation->plan['conflicts'] ?? [])->not->toBeEmpty();

    // Independent: changing the source later never reaches the copy.
    $source->update(['description' => 'Changed later']);
    expect($copy->fresh()->description)->toBe('People team');
});

it('clones a tenant-wide designation for one company: current entity only, references reused, blocked when they do not apply', function () {
    $grade = Grade::factory()->create(['code' => 'G1']);
    $source = Designation::factory()->create(['name' => 'Engineer', 'code' => 'ENG', 'grade_id' => $grade->id]);

    $copy = ($this->clone)('designation', $source->id, [new Destination($this->fe->id, 'FynnEdge engineer', 'ENG_FE')])->copies[0];
    expect(app(EntityApplicability::class)->reach($copy))->toBe([$this->fe->id])
        ->and($copy->grade_id)->toBe($grade->id)
        ->and(app(EntityApplicability::class)->reach($source->fresh()))->toBeNull();

    // A grade limited to Markedge blocks a clone for FynnEdge (dependency validation before the copy is made).
    app(EntityApplicability::class)->apply($source->fresh(), ApplicabilitySelection::single($this->mk->id), $this->admin);
    app(EntityApplicability::class)->apply($grade, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin);
    $narrow = Grade::factory()->create(['code' => 'G2']);
    app(EntityApplicability::class)->apply($narrow, ApplicabilitySelection::single($this->mk->id), $this->admin);
    $markedgeOnly = Designation::factory()->create(['name' => 'Markedge lead', 'code' => 'MK_LEAD', 'grade_id' => $narrow->id]);
    app(EntityApplicability::class)->apply($markedgeOnly, ApplicabilitySelection::single($this->mk->id), $this->admin);

    expect(fn () => ($this->clone)('designation', $markedgeOnly->id, [new Destination($this->fe->id, 'Lead', 'LEAD_FE')]))
        ->toThrow(CloneRefusedException::class, 'which the destination company cannot use');
    expect(Designation::query()->where('code', 'LEAD_FE')->exists())->toBeFalse();
});

it('clones an onboarding template with its checklist items, bound to the destination, and new ids', function () {
    $doc = DocumentType::query()->create(['name' => 'Signed offer', 'code' => 'CFA_OFFER', 'category' => 'employment']);
    $mkDelhi = Location::factory()->create(['name' => 'Delhi', 'company_id' => $this->mk->id]);
    $feDelhi = Location::factory()->create(['name' => 'Delhi', 'company_id' => $this->fe->id]);
    $source = OnboardingTemplate::create(['name' => 'Markedge onboarding', 'key' => 'MK_ONB', 'kind' => 'onboarding', 'priority' => 10, 'status' => 'active', 'conditions' => [
        ['field' => 'company_id', 'operator' => 'in', 'value' => [$this->mk->id]],
        ['field' => 'location_id', 'operator' => 'in', 'value' => [$mkDelhi->id]],
    ]]);
    foreach (['Laptop', 'Offer letter', 'Induction'] as $i => $title) {
        OnboardingTemplateItem::create(['onboarding_template_id' => $source->id, 'phase' => 'pre_joining', 'type' => $i === 1 ? 'document' : 'task', 'title' => $title,
            'document_type_id' => $i === 1 ? $doc->id : null, 'owner_type' => 'hr', 'sort_order' => $i]);
    }

    $result = ($this->clone)('onboarding_template', $source->id, [new Destination($this->fe->id, 'FynnEdge – Standard Employee Onboarding', 'FE_ONB')]);
    $copy = $result->copies[0]->fresh();

    expect($copy->key)->toBe('FE_ONB')
        ->and($copy->status->value)->toBe('inactive')
        ->and($copy->conditions)->toEqual([['field' => 'location_id', 'operator' => 'in', 'value' => [$feDelhi->id]]])
        ->and(app(EntityApplicability::class)->reach($copy))->toBe([$this->fe->id])
        ->and($copy->items()->pluck('title')->all())->toBe(['Laptop', 'Offer letter', 'Induction'])
        ->and($copy->items()->pluck('id')->intersect($source->items()->pluck('id'))->all())->toBe([])
        ->and($copy->items()->where('title', 'Offer letter')->value('document_type_id'))->toBe($doc->id)
        ->and($source->items()->count())->toBe(3)
        ->and(ConfigurationLineage::query()->where('adapter', 'onboarding_template.items')->count())->toBe(3);

    // Once activated, the copy is picked for FynnEdge's Delhi joiners and never for Markedge's.
    $copy->update(['status' => 'active']);
    $hire = fn (Company $company, Location $location) => app(HireEmployeeAction::class)->handle(['first_name' => 'New', 'last_name' => 'Joiner'], ['joining_date' => '2026-11-23'],
        ['company_id' => $company->id, 'location_id' => $location->id]);
    expect(app(Onboarding::class)->templateFor($hire($this->fe, $feDelhi))?->key)->toBe('FE_ONB')
        ->and(app(Onboarding::class)->templateFor($hire($this->mk, $mkDelhi))?->key)->toBe('MK_ONB');

    // Future source changes never reach the clone.
    $source->items()->first()->update(['title' => 'Laptop and phone']);
    expect($copy->items()->orderBy('sort_order')->value('title'))->toBe('Laptop');
});

it('refuses a code already in use and a person without the clone permissions', function () {
    $source = Department::factory()->create(['name' => 'Finance', 'code' => 'FIN', 'company_id' => $this->mk->id]);
    expect(fn () => ($this->clone)('department', $source->id, [new Destination($this->fe->id, 'Finance', 'fin')]))->toThrow(CloneRefusedException::class);

    $noClone = tenantUser($this->tenant, ['organisation.*']);
    expect(fn () => $this->cloner->execute(new CloneRequest('department', $source->id, [new Destination($this->fe->id, 'Finance', 'FIN_FE')], reason: 'x', acknowledged: true), $noClone))
        ->toThrow(CloneRefusedException::class, 'Your roles do not let you clone departments.');
    expect(Department::query()->where('code', 'FIN_FE')->exists())->toBeFalse();
});

it('serves every registered clone type with an adapter that snapshots without touching transactional tables', function () {
    $registry = app(CloneRegistry::class);
    foreach (app(ConfigurationTypes::class)->all() as $type) {
        if (! $type->supportsCloning()) {
            continue;
        }
        $adapter = $registry->for($type->clone);
        expect($adapter->model())->toBe($type->model)
            ->and($adapter->excluded())->each->toBeString();
        if ($adapter instanceof GenericCloneAdapter) {
            $table = (new $type->model)->getTable();
            expect(Schema::hasColumn($table, $adapter->codeColumn()))->toBeTrue("{$type->key}: no {$adapter->codeColumn()} column")
                ->and(Schema::hasColumn($table, $adapter->nameColumn()))->toBeTrue("{$type->key}: no {$adapter->nameColumn()} column");
        }
    }
});
