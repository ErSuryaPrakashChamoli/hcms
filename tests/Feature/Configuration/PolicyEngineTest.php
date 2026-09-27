<?php

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->policies = app(Policies::class);
    $this->resolver = app(PolicyResolver::class);

    $this->company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['name' => 'Delhi', 'company_id' => $this->company->id]);
    $this->sales = Department::factory()->create(['name' => 'Sales']);
    $this->finance = Department::factory()->create(['name' => 'Finance']);

    $this->standard = Policy::create(['type' => 'leave', 'name' => 'Standard Leave', 'code' => 'STD']);
    $this->policies->draft($this->standard, ['annual_entitlement' => 21]);
    $this->policies->publish($this->standard, '2026-01-01');

    $this->salesLeave = Policy::create(['type' => 'leave', 'name' => 'Sales Leave', 'code' => 'SALES']);
    $this->policies->draft($this->salesLeave, ['annual_entitlement' => 18]);
    $this->policies->publish($this->salesLeave, '2026-01-01');

    PolicyAssignmentRule::create(['policy_type' => 'leave', 'policy_id' => $this->standard->id, 'name' => 'Everyone', 'priority' => 100, 'conditions' => []]);
    PolicyAssignmentRule::create(['policy_type' => 'leave', 'policy_id' => $this->salesLeave->id, 'name' => 'Sales in Delhi', 'priority' => 10, 'conditions' => [
        ['field' => 'department_id', 'operator' => 'equals', 'value' => $this->sales->id],
        ['field' => 'location_id', 'operator' => 'equals', 'value' => $this->delhi->id],
    ]]);

    $this->hire = fn (Department $dept, ?Location $loc) => app(HireEmployeeAction::class)->handle(
        ['first_name' => fake()->firstName(), 'last_name' => 'X'],
        ['joining_date' => '2025-01-01'],
        ['company_id' => $this->company->id, 'department_id' => $dept->id, 'location_id' => $loc?->id],
    );
});

it('resolves the highest-priority matching rule, then falls back', function () {
    $salesDelhi = ($this->hire)($this->sales, $this->delhi);
    $salesElsewhere = ($this->hire)($this->sales, null);
    $finance = ($this->hire)($this->finance, $this->delhi);

    expect($this->resolver->resolve('leave', $salesDelhi)->policy->code)->toBe('SALES')
        ->and($this->resolver->resolve('leave', $salesElsewhere)->policy->code)->toBe('STD')
        ->and($this->resolver->resolve('leave', $finance)->policy->code)->toBe('STD')
        ->and($this->resolver->resolve('leave', $finance)->setting('annual_entitlement'))->toBe(21)
        ->and($this->resolver->resolve('attendance', $finance))->toBeNull()
        ->and($this->resolver->resolveAll($salesDelhi)['leave']->policy->code)->toBe('SALES');
});

it('respects effective dates on versions and rules', function () {
    $employee = ($this->hire)($this->finance, null);

    expect($this->resolver->resolve('leave', $employee, '2025-06-01'))->toBeNull();

    $this->policies->draft($this->standard, ['annual_entitlement' => 24]);
    $this->policies->publish($this->standard, '2027-01-01', 'Uplift');

    expect($this->resolver->resolve('leave', $employee, '2026-06-01')->setting('annual_entitlement'))->toBe(21)
        ->and($this->resolver->resolve('leave', $employee, '2027-06-01')->setting('annual_entitlement'))->toBe(24)
        ->and($this->standard->versions()->where('version', 1)->first()->effective_to->toDateString())->toBe('2026-12-31');
});

it('keeps published versions immutable and supports restore as a new draft', function () {
    $v1 = $this->standard->versionEffectiveOn();

    expect(fn () => $v1->update(['settings' => ['annual_entitlement' => 99]]))->toThrow(ConfigurationException::class);
    expect(fn () => $this->policies->publish($this->standard))->toThrow(ConfigurationException::class, 'Nothing to publish');

    $draft = $this->policies->restore($v1, 'Undo');

    expect($draft->status)->toBe(VersionStatus::Draft)
        ->and($draft->version)->toBe(2)
        ->and($draft->settings)->toBe(['annual_entitlement' => 21])
        ->and($draft->change_note)->toContain('Restored from v1');
});

it('rejects a publish that would start before the current version', function () {
    $this->policies->draft($this->standard, ['annual_entitlement' => 30]);

    expect(fn () => $this->policies->publish($this->standard, '2025-12-01'))->toThrow(ConfigurationException::class, 'already starts');

    // Same-day republish retires the earlier version instead of failing.
    $this->policies->publish($this->standard, '2026-01-01');
    expect($this->standard->versions()->where('status', 'retired')->count())->toBe(1)
        ->and($this->standard->versionEffectiveOn('2026-06-01')->setting('annual_entitlement'))->toBe(30);
});

it('lists the employees a rule captures for the impact preview', function () {
    ($this->hire)($this->sales, $this->delhi);
    ($this->hire)($this->sales, $this->delhi);
    ($this->hire)($this->finance, $this->delhi);

    $rule = PolicyAssignmentRule::query()->where('name', 'Sales in Delhi')->first();

    expect($this->resolver->employeesMatching($rule))->toHaveCount(2);
});
