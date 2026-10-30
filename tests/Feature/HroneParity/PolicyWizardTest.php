<?php

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Configuration\Services\PolicySetup;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Department;
use App\Filament\Pages\PolicyWizard;
use App\Filament\Support\PolicySettingsSchema;
use Livewire\Livewire;

/* HROne setup parity: every policy is drafted, changed and published through a step-by-step wizard. */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->wizard = fn (array $query) => Livewire::withQueryParams($query)->test(PolicyWizard::class);
    $this->toReview = function ($page) {
        while (! $page->instance()->isReview()) {
            $page->call('next')->assertHasNoFormErrors();
        }

        return $page;
    };
});

it('splits every policy type into steps: policy, its sections, applicability, advance applicability and review', function () {
    foreach (array_keys(config('peopleos.policies.types')) as $type) {
        $steps = PolicySettingsSchema::steps($type);
        expect($steps)->not->toBeEmpty($type);
        foreach ($steps as $step) {
            expect($step['components'])->not->toBeEmpty("{$type}: {$step['label']}");
        }
    }

    $labels = array_column(($this->wizard)(['type' => 'attendance'])->instance()->steps(), 'label');
    expect($labels[0])->toBe('Policy')
        ->and($labels)->toContain('Calculation', 'Attendance regularisation (AR)', 'Alerts and lock')
        ->and(array_slice($labels, -3))->toBe(['Applicability', 'Advance applicability', 'Review']);
});

it('walks a new policy through the steps, keeps unanswered follow-ups out, and publishes it for everyone', function () {
    $page = ($this->wizard)(['type' => 'attendance'])
        ->call('next')->assertHasFormErrors(['name' => 'required'])->assertSet('step', 0)
        ->fillForm(['name' => 'General attendance'])->assertFormSet(['code' => 'GENERAL_ATTENDANCE'])
        ->call('next')->assertSet('step', 1)
        ->call('goTo', 5)->assertSet('step', 1) // a step not reached yet stays shut
        ->fillForm(['settings.manager_approval_required' => true, 'settings.punch_mode' => 'single', 'settings.request_lock' => 0, 'settings.request_lock_from_day' => 3]); // a Yes / No answer arrives as 1 / 0
    ($this->toReview)($page)->assertSee('General attendance')->assertSee('Single (one punch is a full day)')
        ->call('publish')->assertHasFormErrors(['audit_reason' => 'required'])
        ->fillForm(['audit_reason' => 'First attendance policy'])->call('publish')->assertHasNoFormErrors();

    $policy = Policy::query()->where('code', 'GENERAL_ATTENDANCE')->firstOrFail();
    $version = $policy->versions()->first();
    expect($version->status)->toBe(VersionStatus::Published)
        ->and($version->settings)->toMatchArray(['punch_mode' => 'single', 'manager_approval_required' => true])
        ->and($version->settings)->not->toHaveKey('request_lock_from_day') // its parent says no
        ->and($policy->assignmentRules()->sole()->only(['name', 'priority', 'conditions']))->toBe(['name' => 'Applicability', 'priority' => 1000, 'conditions' => []])
        ->and(app(PolicyResolver::class)->resolve('attendance', Employee::factory()->create(), now())?->setting('punch_mode'))->toBe('single');
});

it('saves a draft for groups and named employees, reopens it with its answers, and publishes the change', function () {
    $sales = Department::factory()->create(['name' => 'Sales']);
    $named = Employee::factory()->create();
    $page = ($this->wizard)(['type' => 'overtime'])->fillForm(['name' => 'Sales overtime', 'code' => 'OT_SALES'])->call('next')
        ->fillForm(['settings.minimum_minutes' => 30]);
    while ($page->instance()->steps()[$page->get('step')]['key'] !== 'applicability') {
        $page->call('next');
    }
    $page->fillForm(['applies_to' => 'groups'])->call('next')->assertHasFormErrors(['applies_to'])
        ->fillForm(['applicability.department_id' => [$sales->id]])->call('next')->assertHasNoFormErrors()
        ->fillForm(['employees' => [$named->id], 'rules_until' => '2026-01-01', 'effective_from' => '2026-06-01'])->call('next')->assertHasFormErrors(['rules_until'])
        ->fillForm(['rules_until' => null])->call('next')
        ->fillForm(['audit_reason' => 'Sales asked'])->call('saveDraft')->assertHasNoFormErrors();

    $policy = Policy::query()->where('code', 'OT_SALES')->firstOrFail();
    $rules = $policy->assignmentRules()->orderBy('priority')->get();
    expect($policy->versions()->sole()->status)->toBe(VersionStatus::Draft)
        ->and($rules->pluck('priority', 'name')->all())->toBe(['Specific employees' => 50, 'Applicability' => 100])
        ->and($rules[1]->conditions)->toBe([['field' => 'department_id', 'operator' => 'in', 'value' => [$sales->id]]])
        ->and($rules[0]->conditions)->toBe([['field' => 'employee_id', 'operator' => 'in', 'value' => [$named->id]]]);

    $page = ($this->wizard)(['policy' => $policy->id])
        ->assertFormSet(['name' => 'Sales overtime', 'settings.minimum_minutes' => 30, 'applies_to' => 'groups', 'applicability.department_id' => [$sales->id], 'employees' => [$named->id]])
        ->call('goTo', 3)->assertSet('step', 3); // an existing policy opens every step
    $page->set('step', count($page->instance()->steps()) - 3)->fillForm(['applies_to' => 'everyone']);
    ($this->toReview)($page)->fillForm(['employees' => [], 'audit_reason' => 'Everyone now', 'effective_from' => now()->toDateString()])->call('publish')->assertHasNoFormErrors();

    expect($policy->versions()->sole()->status)->toBe(VersionStatus::Published)
        ->and($policy->assignmentRules()->sole()->only(['priority', 'conditions']))->toBe(['priority' => 100, 'conditions' => []]); // the adopted rule keeps its priority
});

it('leaves rules it cannot edit to the rule editor, and keeps a policy that applies to nobody that way', function () {
    $policy = Policy::create(['type' => 'overtime', 'name' => 'Custom', 'code' => 'OT_CUSTOM']);
    app(Policies::class)->draft($policy, ['minimum_minutes' => 15]);
    $rule = PolicyAssignmentRule::create(['policy_type' => 'overtime', 'policy_id' => $policy->id, 'name' => 'Not sales', 'conditions' => [['field' => 'department_id', 'operator' => 'not_in', 'value' => [1]]]]);
    $bare = Policy::create(['type' => 'overtime', 'name' => 'Bare', 'code' => 'OT_BARE']);

    expect(app(PolicySetup::class)->manageable($policy))->toBeFalse()
        ->and(app(PolicySetup::class)->load($bare)['applies_to'])->toBe('employees');
    $page = ($this->wizard)(['policy' => $policy->id])->assertSet('advancedRules', true);
    $last = count($page->instance()->steps()) - 1;
    $page->set('step', $last - 2)->assertSee('the wizard does not edit')
        ->set('step', $last)->fillForm(['audit_reason' => 'Tweak'])->call('saveDraft')->assertHasNoFormErrors();
    expect($rule->fresh()->conditions)->toBe([['field' => 'department_id', 'operator' => 'not_in', 'value' => [1]]])
        ->and($policy->assignmentRules()->count())->toBe(1);

    app(PolicySetup::class)->save(app(PolicySetup::class)->load($bare), $bare, $this->admin, false, 'No change to who');
    expect($bare->assignmentRules()->count())->toBe(0);
});

it('needs policy permissions: create to start one, update to change one, publish to publish', function () {
    $policy = Policy::create(['type' => 'overtime', 'name' => 'OT', 'code' => 'OT_P']);
    $drafter = tenantUser($this->tenant, ['policy.view', 'policy.create', 'policy.update']);
    $this->actingAs(tenantUser($this->tenant, ['policy.view']));
    $this->get(PolicyWizard::getUrl())->assertForbidden();

    $this->actingAs($drafter);
    $page = ($this->wizard)(['policy' => $policy->id]);
    $page->set('step', count($page->instance()->steps()) - 1)->assertDontSeeHtml('wire:click="publish"')
        ->fillForm(['audit_reason' => 'Draft only'])->call('publish')->assertForbidden();
    expect(fn () => app(PolicySetup::class)->save(app(PolicySetup::class)->load($policy), $policy, $drafter, true, 'x'))->toThrow(RuntimeException::class, 'cannot publish');
});
