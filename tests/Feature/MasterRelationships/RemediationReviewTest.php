<?php

use App\Domain\Configuration\Enums\ChangeStatus;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Configuration\Services\ConfigurationChanges;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Requirements\EmploymentRequirementPreflight as Run;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Employment\Requirements\EmploymentRequirementsPreflight;
use App\Domain\Entitlements\Services\EntitlementStateStore;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Services\DesignationDefaults;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\EmploymentRequirementsPage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Fixes from the independent review of the remediation (25 Nov 2026): a pre-flight that did not complete never
| authorises Block; a cancel is never overwritten; a run another worker reads is not taken; examples of a queued run
| only for people the viewer may see; a company check that finishes after its date ends from today; level and grade
| pairs count ended values still applying and leave old pairs alone; a refused scheduled change does not jam the others;
| the entitlement generation never goes back to an older version.
*/

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->grade = Grade::query()->create(['name' => 'T4', 'code' => 'T4']);
    $this->preflight = app(EmploymentRequirementsPreflight::class);
});

function rrPolicy(array $settings): Policy
{
    $policy = Policy::query()->create(['type' => EmploymentRequirements::TYPE, 'name' => 'Hire rules', 'code' => 'EDR']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2026-01-01');
    PolicyAssignmentRule::query()->create(['policy_type' => EmploymentRequirements::TYPE, 'policy_id' => $policy->id, 'name' => 'All', 'priority' => 100, 'conditions' => []]);

    return $policy;
}

function rrHire(Company $company, array $position = []): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2026-11-02'], ['company_id' => $company->id] + $position);
}

it('does not switch to Block on a pre-flight that was superseded while it read people', function () {
    rrHire($this->mk, ['grade_id' => $this->grade->id]);
    $policy = rrPolicy(['required_on_hire' => ['grade_id']]);
    // While the pre-flight reads people, FynnEdge is given its own values: the run no longer describes the policy.
    $changed = false;
    Event::listen('eloquent.retrieved: '.Employee::class, function () use (&$changed, $policy) {
        if (! $changed) {
            $changed = true;
            app(ConfigurationOverrides::class)->set($policy, $this->fe->id, 'required_on_change', ['grade_id'], '2026-11-25', $this->admin, 'Meanwhile');
        }
    });

    expect(fn () => $this->preflight->switchToBlock($policy, ['required'], $this->admin, 'Block'))->toThrow(Exception::class, 'did not complete (superseded');
    expect(Run::query()->sole()->status)->toBe(Run::SUPERSEDED)
        ->and($policy->versionEffectiveOn(today())->settings['required_mode'] ?? 'warn')->toBe('warn');
});

it('keeps a cancel that lands after the last batch, ends nothing, and never takes a run another worker reads', function () {
    rrHire($this->fe);
    $policy = rrPolicy(['required_on_hire' => ['grade_id']]);
    app(ConfigurationOverrides::class)->set($policy, $this->fe->id, 'required_on_hire', [], '2026-11-25', $this->admin, 'Own');
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block', acceptCurrentGaps: true);
    config(['peopleos.employment_requirements.preflight_queue_threshold' => 0]);   // from here on, checks are queued
    Queue::fake();
    $pending = $this->preflight->endCompanyValues($policy, $this->fe->id, ['required_on_hire'], $this->admin, 'Shared rules', acceptCurrentGaps: true);
    expect($pending['outcome'])->toBe('pending');
    $run = $pending['run'];

    // Cancelled after people were read, while the companies are checked: it stays cancelled, the value is not ended.
    DB::listen(function (QueryExecuted $q) use ($run) {
        if (str_contains($q->sql, 'from "companies"') || str_contains($q->sql, 'from `companies`')) {
            Run::query()->whereKey($run->id)->where('status', Run::RUNNING)->update(['status' => Run::CANCELLED]);
        }
    });
    $this->preflight->execute($run);
    expect($run->fresh()->status)->toBe(Run::CANCELLED)->and($run->fresh()->outcome)->toBeNull()
        ->and(app(ConfigurationOverrides::class)->valuesFor('policy', $policy->id, $this->fe->id))->toHaveKey('required_on_hire');

    // A run another worker is reading (within the job's time limit) is not taken; past it, it is.
    $other = $this->preflight->record($policy, $this->admin);
    Run::query()->whereKey($other->id)->update(['status' => Run::RUNNING, 'started_at' => now()]);
    expect($this->preflight->execute($other->fresh())->status)->toBe(Run::RUNNING)->and($other->fresh()->report)->toBeNull();
    Run::query()->whereKey($other->id)->update(['started_at' => now()->subHours(2)]);
    expect($this->preflight->execute($other->fresh())->status)->not->toBe(Run::RUNNING);
});

it('ends a company\'s values from today when its queued check finishes after the date asked for', function () {
    rrHire($this->fe, ['grade_id' => $this->grade->id]);
    $policy = rrPolicy(['required_on_hire' => ['grade_id']]);
    app(ConfigurationOverrides::class)->set($policy, $this->fe->id, 'required_on_hire', [], '2026-11-25', $this->admin, 'Own');
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block');
    config(['peopleos.employment_requirements.preflight_queue_threshold' => 0]);   // from here on, checks are queued
    Queue::fake();
    $run = $this->preflight->endCompanyValues($policy, $this->fe->id, ['required_on_hire'], $this->admin, 'Shared rules', '2026-11-25')['run'];

    $this->travelTo('2026-11-26 09:00:00');
    $done = $this->preflight->execute($run);
    expect($done->outcome)->toBe('covered')
        ->and(ConfigurationOverride::query()->where('field', 'required_on_hire')->where('company_id', $this->fe->id)->sole()->effective_to->toDateString())->toBe('2026-11-25');
});

it('shows a queued run\'s examples only for people the viewer may see', function () {
    config(['peopleos.employment_requirements.preflight_queue_threshold' => 0]);
    $mine = rrHire($this->mk);
    $theirs = rrHire($this->fe);
    $policy = rrPolicy(['required_on_hire' => ['grade_id'], 'required_on_change' => ['grade_id']]);
    Queue::fake();
    $run = $this->preflight->record($policy, $this->admin);
    $this->preflight->execute($run);   // as the administrator: examples from both companies

    $limited = tenantUser($this->tenant, ['policy.view']);
    app(AccessScopes::class)->assign($limited, ['company' => [$this->mk->id]]);
    $this->actingAs($limited->fresh());
    $samples = Livewire::test(EmploymentRequirementsPage::class)->get('preflight')['rules']['required']['samples'];
    expect($samples)->toHaveCount(1)->and($samples[0])->toStartWith($mine->employee_code.' ')
        ->and(collect($samples)->filter(fn ($s) => str_starts_with($s, $theirs->employee_code.' ')))->toBeEmpty();
});

it('counts a company value that is ended from a later date as applying until then, and leaves old pairs alone', function () {
    $l5 = Level::query()->create(['name' => 'L5', 'code' => 'RR-L5']);
    $l6 = Level::query()->create(['name' => 'L6', 'code' => 'RR-L6']);
    $t4 = Grade::query()->create(['name' => 'G4', 'code' => 'G4', 'level_id' => $l5->id]);
    $t5 = Grade::query()->create(['name' => 'G5', 'code' => 'G5', 'level_id' => $l5->id]);
    $t6 = Grade::query()->create(['name' => 'G6', 'code' => 'G6', 'level_id' => $l6->id]);
    $designation = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC', 'level_id' => $l5->id, 'grade_id' => $t4->id]);
    $overrides = app(ConfigurationOverrides::class);
    $overrides->set($designation, $this->fe->id, 'grade_id', $t5->id, '2026-11-25', $this->admin, 'FynnEdge grade');
    $overrides->end($designation, $this->fe->id, 'grade_id', '2027-01-01', $this->admin, 'Shared from January');

    // Until 31 Dec FynnEdge still has G5 (a grade of L5): the shared pair cannot move to L6 / G6 under it.
    expect(fn () => $designation->update(['level_id' => $l6->id, 'grade_id' => $t6->id]))->toThrow(Exception::class, "FynnEdge Advisory's values");
    expect(app(DesignationDefaults::class)->for($designation, $this->fe->id, '2026-12-31')['grade_id'])->toBe($t5->id);

    // A pair that was already wrong before the rule does not stop a grade moving elsewhere.
    DB::table('designations')->where('id', $designation->id)->update(['grade_id' => $t6->id]);   // L5 with G6 (of L6)
    $t6->update(['level_id' => Level::query()->create(['name' => 'L7', 'code' => 'RR-L7'])->id]);
    expect($t6->fresh()->level_id)->not->toBe($l6->id);
});

it('keeps a refused scheduled change from stopping the others, and says why', function () {
    app(FeatureFlags::class)->set('configuration.approval', true);
    app(SettingsRepository::class)->set('configuration.approval.minimum_risk', 'low');
    $l5 = Level::query()->create(['name' => 'L5', 'code' => 'RR-L5']);
    $l6 = Level::query()->create(['name' => 'L6', 'code' => 'RR-L6']);
    $g5 = Grade::query()->create(['name' => 'G5', 'code' => 'G5', 'level_id' => $l6->id]);
    $designation = Designation::query()->create(['name' => 'Buyer', 'code' => 'BUY', 'level_id' => $l5->id]);
    $changes = app(ConfigurationChanges::class);
    $first = $changes->propose($designation, ['level_id' => $l6->id, 'grade_id' => $g5->id], 'Regrade', now()->addDays(3));
    $changes->approve($first);
    $second = $changes->propose($this->mk, ['name' => 'Markedge Technologies India'], 'Rename', now()->addDays(3));
    $changes->approve($second);
    // Meanwhile G5 moves to L5 (nothing pairs it with L6 yet): the scheduled regrade no longer agrees.
    $g5->update(['level_id' => $l5->id]);

    $this->travel(4)->days();
    expect($changes->publishDue())->toBe(1)
        ->and($second->fresh()->status)->toBe(ChangeStatus::Published)
        ->and($first->fresh()->status)->toBe(ChangeStatus::Scheduled)
        ->and($first->fresh()->review_note)->toContain('Not published: Buyer: Grade G5 belongs to level L5, not L6.');
});

it('never moves the entitlement generation back to an older version', function () {
    $store = app(EntitlementStateStore::class);
    $store->forget($this->tenant->id, 6);
    $store->forget($this->tenant->id, 5);   // an older change's call arriving late
    expect((int) strtok((string) cache()->get(EntitlementStateStore::generationKey($this->tenant->id)), '.'))->toBe(6);
    $store->forget($this->tenant->id);      // no version: keeps the version, new generation
    expect((int) strtok((string) cache()->get(EntitlementStateStore::generationKey($this->tenant->id)), '.'))->toBe(6);
});
