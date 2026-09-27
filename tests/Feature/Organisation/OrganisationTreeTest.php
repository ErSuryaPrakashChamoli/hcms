<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Exceptions\InvalidHierarchyException;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Models\Team;
use App\Domain\Organisation\Services\OrganisationTree;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->tree = app(OrganisationTree::class);

    $this->company = Company::factory()->create(['name' => 'Acme Group', 'code' => 'ACME']);
    $this->root = $this->tree->attach($this->company);
});

it('places a company at the root with a materialised path', function () {
    expect($this->root->parent_id)->toBeNull()
        ->and($this->root->depth)->toBe(0)
        ->and($this->root->path)->toBe("/{$this->root->id}/")
        ->and($this->root->typeKey())->toBe('company')
        ->and($this->root->auditLabel())->toBe('Company: Acme Group');
});

it('creates units beneath a parent and inherits the owning company', function () {
    $bu = $this->tree->createUnit('business_unit', ['name' => 'Technology', 'code' => 'TECH'], $this->root, 'Initial structure');
    $dept = $this->tree->createUnit('department', ['name' => 'Engineering', 'code' => 'ENG'], $bu);

    expect($bu->nodeable)->toBeInstanceOf(BusinessUnit::class)
        ->and($bu->nodeable->company_id)->toBe($this->company->id)
        ->and($dept->nodeable)->toBeInstanceOf(Department::class)
        ->and($dept->nodeable->company_id)->toBe($this->company->id)
        ->and($dept->depth)->toBe(2)
        ->and($dept->path)->toBe("/{$this->root->id}/{$bu->id}/{$dept->id}/")
        ->and(AuditEvent::query()->where('entity_type', BusinessUnit::class)->value('reason'))->toBe('Initial structure');
});

it('enforces the configured containment rules', function () {
    $dept = $this->tree->createUnit('department', ['name' => 'Finance', 'code' => 'FIN'], $this->root);

    expect(fn () => $this->tree->createUnit('business_unit', ['name' => 'X', 'code' => 'X'], $dept))
        ->toThrow(InvalidHierarchyException::class, 'cannot sit beneath a Department');

    expect(fn () => $this->tree->attach(Team::factory()->create()))
        ->toThrow(InvalidHierarchyException::class, 'at the root');
});

it('refuses to wrap the same unit twice', function () {
    expect(fn () => $this->tree->attach($this->company))->toThrow(QueryException::class);
});

it('moves a subtree and rewrites every descendant path and depth', function () {
    $sales = $this->tree->createUnit('business_unit', ['name' => 'Sales', 'code' => 'SALES'], $this->root);
    $ops = $this->tree->createUnit('business_unit', ['name' => 'Ops', 'code' => 'OPS'], $this->root);
    $dept = $this->tree->createUnit('department', ['name' => 'Inside Sales', 'code' => 'ISALES'], $sales);
    $team = $this->tree->createUnit('team', ['name' => 'SDRs', 'code' => 'SDR'], $dept);

    $this->tree->move($dept, $ops, 'Restructure');

    $dept->refresh();
    $team->refresh();

    expect($dept->parent_id)->toBe($ops->id)
        ->and($dept->path)->toBe("/{$this->root->id}/{$ops->id}/{$dept->id}/")
        ->and($dept->depth)->toBe(2)
        ->and($team->path)->toBe("/{$this->root->id}/{$ops->id}/{$dept->id}/{$team->id}/")
        ->and($team->depth)->toBe(3);

    $event = $dept->auditEvents()->where('action', 'UPDATE')->first();
    expect($event->reason)->toBe('Restructure')
        ->and($event->fieldChanges->firstWhere('field', 'parent_id')->before)->toBe((string) $sales->id)
        ->and($event->fieldChanges->firstWhere('field', 'parent_id')->after)->toBe((string) $ops->id);
});

it('never lets a unit be moved beneath itself or its descendants', function () {
    $bu = $this->tree->createUnit('business_unit', ['name' => 'BU', 'code' => 'BU'], $this->root);
    $dept = $this->tree->createUnit('department', ['name' => 'D', 'code' => 'D'], $bu);

    expect(fn () => $this->tree->move($bu, $dept))->toThrow(InvalidHierarchyException::class, 'beneath itself')
        ->and(fn () => $this->tree->move($bu, $bu))->toThrow(InvalidHierarchyException::class);
});

it('deactivates a whole subtree but reactivates only the node itself', function () {
    $bu = $this->tree->createUnit('business_unit', ['name' => 'BU', 'code' => 'BU'], $this->root);
    $dept = $this->tree->createUnit('department', ['name' => 'D', 'code' => 'D'], $bu);

    $this->tree->setStatus($bu, ActiveStatus::Inactive, 'Closed');

    expect($bu->refresh()->status)->toBe(ActiveStatus::Inactive)
        ->and($dept->refresh()->status)->toBe(ActiveStatus::Inactive)
        ->and($dept->nodeable->status)->toBe(ActiveStatus::Inactive)
        ->and(BusinessUnit::query()->find($bu->nodeable_id)->status)->toBe(ActiveStatus::Inactive);

    $this->tree->setStatus($bu, ActiveStatus::Active);

    expect($bu->refresh()->status)->toBe(ActiveStatus::Active)
        ->and($dept->refresh()->status)->toBe(ActiveStatus::Inactive);
});

it('reorders siblings', function () {
    $a = $this->tree->createUnit('business_unit', ['name' => 'A', 'code' => 'A'], $this->root);
    $b = $this->tree->createUnit('business_unit', ['name' => 'B', 'code' => 'B'], $this->root);
    $c = $this->tree->createUnit('business_unit', ['name' => 'C', 'code' => 'C'], $this->root);

    $names = fn () => $this->root->children()->with('nodeable')->get()->map(fn ($n) => $n->nodeable->name)->all();

    $this->tree->reorder($c, 'up');
    expect($names())->toBe(['A', 'C', 'B']);

    $this->tree->reorder($a, 'down');
    expect($names())->toBe(['C', 'A', 'B']);

    $this->tree->reorder($c, 'up'); // already first: no-op
    expect($names())->toBe(['C', 'A', 'B']);
});

it('renames through the underlying unit', function () {
    $this->tree->rename($this->root, 'Acme Holdings', 'Legal rename');

    expect($this->company->refresh()->name)->toBe('Acme Holdings')
        ->and($this->company->auditEvents()->where('action', 'UPDATE')->value('reason'))->toBe('Legal rename');
});

it('detaches only leaf nodes and keeps the unit record', function () {
    $bu = $this->tree->createUnit('business_unit', ['name' => 'BU', 'code' => 'BU'], $this->root);
    $this->tree->createUnit('team', ['name' => 'T', 'code' => 'T'], $bu);

    expect(fn () => $this->tree->detach($bu))->toThrow(InvalidHierarchyException::class);
    expect(fn () => $this->tree->detach($this->root))->toThrow(InvalidHierarchyException::class);

    $lonely = $this->tree->createUnit('location', ['name' => 'Pune', 'code' => 'PUNE'], $this->root);
    $this->tree->detach($lonely);

    expect(OrganisationNode::query()->find($lonely->id))->toBeNull()
        ->and(Location::query()->where('code', 'PUNE')->exists())->toBeTrue();
});

it('builds the nested tree and filters by search keeping ancestors', function () {
    $bu = $this->tree->createUnit('business_unit', ['name' => 'Technology', 'code' => 'TECH'], $this->root);
    $this->tree->createUnit('department', ['name' => 'Engineering', 'code' => 'ENG'], $bu);
    $this->tree->createUnit('department', ['name' => 'Finance', 'code' => 'FIN'], $this->root);

    $full = $this->tree->tree();
    expect($full)->toHaveCount(1)
        ->and($full->first()->children)->toHaveCount(2)
        ->and($full->first()->children->first()->children->first()->nodeable->name)->toBe('Engineering');

    $filtered = $this->tree->tree('eng');
    $names = [];
    $walk = function ($nodes) use (&$walk, &$names) {
        foreach ($nodes as $n) {
            $names[] = $n->nodeable->name;
            $walk($n->children);
        }
    };
    $walk($filtered);

    expect($names)->toBe(['Acme Group', 'Technology', 'Engineering'])
        ->and($filtered->first()->matches_search)->toBeFalse();
});

it('lists valid parents for a move, excluding the subtree being moved', function () {
    $bu = $this->tree->createUnit('business_unit', ['name' => 'BU', 'code' => 'BU'], $this->root);
    $dept = $this->tree->createUnit('department', ['name' => 'D', 'code' => 'D'], $bu);
    $other = $this->tree->createUnit('department', ['name' => 'Other', 'code' => 'OTH'], $this->root);

    $options = $this->tree->options($bu, 'business_unit');

    expect(array_keys($options))->toBe([$this->root->id])
        // Departments may nest beneath departments (config), so $dept is a valid parent for $other.
        ->and(array_keys($this->tree->options($other, 'department')))->toEqualCanonicalizing([$this->root->id, $bu->id, $dept->id]);
});

it('keeps trees isolated per tenant', function () {
    $other = provisionTenant('Other');
    actAsTenant($other);

    expect($this->tree->tree())->toBeEmpty()
        ->and(OrganisationNode::query()->count())->toBe(0);
});
