<?php

use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Employment\Requirements\EmploymentRequirementsPreflight;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Filament\Pages\EmploymentRequirementsPage;
use Livewire\Livewire;

/*
| Remediation I.9: a person limited to some companies still cannot change, publish or block a policy (or any record)
| shared beyond them. The refusal now says why, how many other companies it reaches (never which), who can make the
| change, and what they can do for their own companies; the requirements page says so before they try.
*/

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->policy = Policy::query()->create(['type' => EmploymentRequirements::TYPE, 'name' => 'Hire rules', 'code' => 'EDR']);
    app(Policies::class)->draft($this->policy, ['required_on_hire' => ['grade_id']]);
    app(Policies::class)->publish($this->policy, '2026-01-01');
    PolicyAssignmentRule::query()->create(['policy_type' => EmploymentRequirements::TYPE, 'policy_id' => $this->policy->id, 'name' => 'All', 'priority' => 100, 'conditions' => []]);
    $this->limited = tenantUser($this->tenant, ['policy.view', 'policy.update', 'policy.publish', 'configuration.override', 'people_setup.view', 'people_setup.update']);
    app(AccessScopes::class)->assign($this->limited, ['company' => [$this->mk->id]]);
    $this->limited = $this->limited->fresh();
});

it('keeps the restriction and explains it: why, how many others, who can, and what to do instead', function () {
    $this->actingAs($this->limited);
    $versions = PolicyVersion::query()->count();

    $refusal = fn () => app(Policies::class)->draft($this->policy, ['required_on_hire' => []]);
    expect($refusal)->toThrow(ConfigurationException::class, 'shared with companies outside your access (1 company)');
    try {
        $refusal();
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->toContain('Someone whose access covers every company it applies to can')
            ->toContain('clone it for your company')->toContain('Company values')->not->toContain('FynnEdge');
    }
    expect(fn () => app(EmploymentRequirementsPreflight::class)->switchToBlock($this->policy, ['required'], $this->limited, 'Block'))
        ->toThrow(ConfigurationException::class, 'switch its rules to Block');
    expect(PolicyVersion::query()->count())->toBe($versions);   // nothing written

    // The same explanation for any shared record (a designation shared with both companies).
    $this->actingAs($this->admin);
    $designation = Designation::query()->create(['name' => 'Analyst', 'code' => 'ANL']);
    $this->actingAs($this->limited);
    expect(fn () => $designation->update(['name' => 'Senior analyst']))->toThrow(ApplicabilityRefusedException::class, 'Someone whose access covers every company');
});

it('says on the requirements page why the policy cannot be changed there, and leaves Switch to Block unavailable', function () {
    $this->actingAs($this->limited);
    Livewire::test(EmploymentRequirementsPage::class)->assertOk()
        ->assertSee('You can view this policy, not change it.')->assertSee('Someone whose access covers every company')
        ->assertActionDisabled('switchToBlock');

    $this->actingAs($this->admin);
    Livewire::test(EmploymentRequirementsPage::class)->assertDontSee('You can view this policy, not change it.')->assertActionEnabled('switchToBlock');
});
