<?php

use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Models\ConfigurationApplicability;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Support\Tenancy\TenantContext;

/*
| Configuration applicability, security (acceptance 13.4, Part 9): every check is on the server. A person limited to
| Markedge can neither apply nor change configuration for FynnEdge; sharing and overriding need their own permissions;
| tenants never see each other's applicability.
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
    $this->designation = Designation::factory()->create(['name' => 'Analyst', 'code' => 'ANL']);

    $this->limited = function (array $keys, int $companyId): User {
        $user = User::factory()->forTenant($this->tenant)->create();
        $role = Role::factory()->create();
        $role->permissions()->sync(app(PermissionRegistry::class)->idsMatching($keys));
        $user->roles()->attach($role, ['company_id' => $companyId]);
        // The access scope that limits the person to the company (what the access-scope screen writes).
        $user->accessScopes()->create(['dimension' => 'company', 'scope_id' => $companyId]);

        return $user->refresh();
    };
});

it('needs configuration.share to make a record reach several companies, and the type\'s own permission', function () {
    $noShare = tenantUser($this->tenant, ['people_setup.*']);
    $noType = tenantUser($this->tenant, ['configuration.share']);

    expect($this->applicability->plan($this->designation, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $noShare)->blockers)
        ->toContain('Your roles do not let you share configuration across several companies.')
        ->and($this->applicability->plan($this->designation, ApplicabilitySelection::single($this->mk->id), $noShare)->blockers)->toBe([])
        ->and($this->applicability->plan($this->designation, ApplicabilitySelection::single($this->mk->id), $noType)->blockers)
        ->toBe(['Your roles do not let you manage designations.']);
});

it('stops a person limited to Markedge from applying or changing configuration for FynnEdge', function () {
    $markedgeAdmin = ($this->limited)(['people_setup.*', 'configuration.share', 'configuration.override'], $this->mk->id);

    // A tenant-wide designation reaches FynnEdge too, so it is beyond their access: no applicability change, no edit.
    expect($this->applicability->plan($this->designation, ApplicabilitySelection::single($this->mk->id), $markedgeAdmin)->blockers)
        ->toContain('This designation applies to companies outside your access, so you cannot change where it applies.');
    $this->actingAs($markedgeAdmin);
    expect(fn () => $this->designation->update(['name' => 'Senior analyst']))->toThrow(ApplicabilityRefusedException::class, 'shared with companies outside your access');
    expect(fn () => $this->designation->delete())->toThrow(ApplicabilityRefusedException::class);

    // What they create applies to their company only, and stays theirs to change; they cannot add FynnEdge or later companies.
    $own = Designation::factory()->create(['name' => 'Markedge analyst', 'code' => 'MK_ANL']);
    expect($this->applicability->reach($own))->toBe([$this->mk->id]);
    $own->update(['name' => 'Markedge senior analyst']);
    expect($own->fresh()->name)->toBe('Markedge senior analyst')
        ->and($this->applicability->plan($own, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $markedgeAdmin)->blockers)
        ->toContain('FynnEdge Advisory is outside your access.')
        ->and($this->applicability->plan($own, ApplicabilitySelection::all(includeFuture: true), $markedgeAdmin)->blockers)
        ->toContain('Only someone with access to every company can include companies created later.');
    expect($this->designation->fresh()->name)->toBe('Analyst');
});

it('previews a record not saved yet as reaching nobody, so a limited person may choose their own companies for it', function () {
    $markedgeAdmin = ($this->limited)(['people_setup.*', 'configuration.share'], $this->mk->id);
    $unsaved = new Designation(['code' => 'LM1', 'name' => 'Mine', 'status' => 'active']);

    expect($this->applicability->plan($unsaved, ApplicabilitySelection::single($this->mk->id), $markedgeAdmin)->blockers)->toBe([])
        ->and($this->applicability->plan($unsaved, ApplicabilitySelection::single($this->mk->id), $markedgeAdmin)->added)->toBe([$this->mk->id])
        ->and($this->applicability->plan($unsaved, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $markedgeAdmin)->blockers)
        ->toBe(['FynnEdge Advisory is outside your access.', 'Your roles do not let you manage designations for FynnEdge Advisory.']);
});

it('needs configuration.override for the company, within the person\'s access', function () {
    $policies = app(Policies::class);
    $policy = Policy::create(['type' => 'attendance', 'name' => 'Shared attendance', 'code' => 'SHARED_ATT']);
    $policies->draft($policy, ['grace_minutes' => 10]);
    $policies->publish($policy, '2026-01-01');
    $overrides = app(ConfigurationOverrides::class);

    $noOverride = tenantUser($this->tenant, ['policy.*']);
    expect(fn () => $overrides->set($policy, $this->fe->id, 'grace_minutes', 20, null, $noOverride))
        ->toThrow(ApplicabilityRefusedException::class, 'Your roles do not let you override policies for FynnEdge Advisory.');

    $markedgeAdmin = ($this->limited)(['policy.*', 'configuration.override'], $this->mk->id);
    expect(fn () => $overrides->set($policy, $this->fe->id, 'grace_minutes', 20, null, $markedgeAdmin))->toThrow(ApplicabilityRefusedException::class);
    // Their own company: allowed, even though the policy is shared (that is what overrides are for).
    $overrides->set($policy, $this->mk->id, 'grace_minutes', 12, null, $markedgeAdmin);
    expect(ConfigurationOverride::query()->sole()->company_id)->toBe($this->mk->id);
});

it('keeps applicability inside the tenant', function () {
    $grade = Grade::factory()->create(['code' => 'G1']);
    $this->applicability->apply($grade, ApplicabilitySelection::single($this->mk->id), $this->admin);

    $other = provisionTenant('Other');
    actAsTenant($other);
    $stranger = Company::factory()->create(['code' => 'OT']);
    expect(ConfigurationApplicability::query()->count())->toBe(0)
        ->and(Grade::query()->find($grade->id))->toBeNull();

    // A company of another tenant is never a valid choice.
    actAsTenant($this->tenant);
    expect($this->applicability->plan($grade, ApplicabilitySelection::selected([$this->mk->id, $stranger->id]), $this->admin)->blockers)
        ->toContain('One of the companies chosen does not exist in this organisation.');
    expect(app(TenantContext::class)->id())->toBe($this->tenant->id);
});
