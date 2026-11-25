<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Jobs\RunEmploymentRequirementsPreflight;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Requirements\EmploymentRequirementPreflight as Run;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Employment\Requirements\EmploymentRequirementsPreflight;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Grade;
use App\Filament\Pages\EmploymentRequirementsPage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Remediation I.7 and I.13.
| I.7: Block holds for a company only while a pre-flight covers its settings as they are, its own values applied. Ending
|      a company's own values the uncontrolled way leaves it on Warn; the controlled way checks it first and then ends
|      them and covers it, or refuses.
| I.13: above the threshold the pre-flight is a queued run (pending → running → completed / failed / superseded /
|      cancelled); only a completed, current and recent run is evidence for Block. Idempotent, retry-safe, per tenant.
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

function prPolicy(array $settings): Policy
{
    $policy = Policy::query()->create(['type' => EmploymentRequirements::TYPE, 'name' => 'Hire rules', 'code' => 'EDR']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2026-01-01');
    PolicyAssignmentRule::query()->create(['policy_type' => EmploymentRequirements::TYPE, 'policy_id' => $policy->id, 'name' => 'Applicability', 'priority' => 100, 'conditions' => []]);

    return $policy;
}

function prHire(Company $company, array $position = [], string $date = '2026-11-02'): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => $date], ['company_id' => $company->id] + $position);
}

/** A transfer within the same company (the change that checks required_on_change). */
function prTransfer(Employee $employee, string $date = '2026-11-26')
{
    return app(AssignPositionAction::class)->handle($employee, ['company_id' => $employee->currentPosition->company_id], 'transfer', $date, 'Move');
}

/**
 * FynnEdge's own value: grades are not required on a change there. Its one person without a grade passes the
 * pre-flight under those values, so "Required fields" is switched to Block for everyone.
 */
function prBlockedWithOwnValues(object $test): array
{
    $theirs = prHire($test->fe);
    prHire($test->mk, ['grade_id' => $test->grade->id]);
    $policy = prPolicy(['required_on_hire' => ['grade_id'], 'required_on_change' => ['grade_id']]);
    app(ConfigurationOverrides::class)->set($policy, $test->fe->id, 'required_on_change', [], '2026-11-25', $test->admin, 'FynnEdge: grade on hire only');
    $test->preflight->switchToBlock($policy, ['required'], $test->admin, 'Block missing grades', '2026-11-25');

    return [$policy->fresh(), $theirs->fresh()];
}

it('records each company\'s settings fingerprint with Block, so ending its own values the uncontrolled way leaves it on Warn', function () {
    [$policy, $theirs] = prBlockedWithOwnValues($this);
    $record = $policy->versionEffectiveOn(today())->settings['preflight'];
    expect($record['company_fingerprints'])->toHaveKeys([$this->mk->id, $this->fe->id])
        ->and($record['company_fingerprints'][$this->fe->id])->not->toBe($record['company_fingerprints'][$this->mk->id]);

    // FynnEdge's own value ends without a check: the shared Block on a change would now apply to it, but nobody checked
    // FynnEdge with those settings, so it warns instead of blocking.
    app(ConfigurationOverrides::class)->end($policy, $this->fe->id, 'required_on_change', '2026-11-26', $this->admin, 'Shared rules');
    $position = prTransfer($theirs);
    expect($position->requirementWarnings)->toHaveCount(1)
        ->and($position->requirementWarnings[0])->toEndWith('It blocks once a pre-flight covers FynnEdge Advisory.');

    // Markedge, whose settings the pre-flight checked, still blocks (a hire without a grade).
    expect(fn () => prHire($this->mk, [], '2026-11-26'))->toThrow(InvalidArgumentException::class, 'Grade is required');
});

it('ends a company\'s own values the controlled way: refused while people would fail, covered once accepted', function () {
    [$policy, $theirs] = prBlockedWithOwnValues($this);

    $refused = $this->preflight->endCompanyValues($policy, $this->fe->id, ['required_on_change'], $this->admin, 'Shared rules', '2026-11-26');
    expect($refused['outcome'])->toBe('refused')
        ->and($refused['message'])->toContain('1 current employees would fail "Required fields"')
        ->and(app(ConfigurationOverrides::class)->valuesFor('policy', $policy->id, $this->fe->id, '2026-11-26'))->toHaveKey('required_on_change');   // nothing ended

    $covered = $this->preflight->endCompanyValues($policy, $this->fe->id, ['required_on_change'], $this->admin, 'Shared rules, gaps accepted', '2026-11-26', acceptCurrentGaps: true);
    expect($covered['outcome'])->toBe('covered')
        ->and($covered['run']->status)->toBe(Run::COMPLETED)->and($covered['run']->report['safe_rules'])->toBe(['required'])
        ->and(app(ConfigurationOverrides::class)->valuesFor('policy', $policy->id, $this->fe->id, '2026-11-26'))->not->toHaveKey('required_on_change');

    // Covered: FynnEdge's next change without a grade is refused from the day its values end; before it, its own still apply.
    expect(fn () => prTransfer($theirs, '2026-11-26'))->toThrow(InvalidArgumentException::class, 'Grade is required');
    expect(prTransfer($theirs, '2026-11-25')->requirementWarnings)->toBe([]);
});

it('ends own values without a pre-flight when nothing the company will have blocks, and refuses a company it cannot meet', function () {
    $theirs = prHire($this->fe);
    $policy = prPolicy(['required_on_hire' => ['grade_id'], 'required_on_change' => ['grade_id']]);
    app(ConfigurationOverrides::class)->set($policy, $this->fe->id, 'required_on_change', [], '2026-11-25', $this->admin, 'Own');
    expect($this->preflight->endCompanyValues($policy, $this->fe->id, ['required_on_change'], $this->admin, 'Shared, all on Warn')['outcome'])->toBe('ended')
        ->and(Run::query()->count())->toBe(0);

    // The run's coverage only fits the settings it checked: other settings (another fingerprint) find nothing.
    expect(EmploymentRequirementsPreflight::companyCoverage($policy->id, $this->fe->id, str_repeat('0', 64)))->toBeNull();
});

it('runs the pre-flight in the request below the threshold and records what Block was based on', function () {
    prHire($this->mk, ['grade_id' => $this->grade->id]);
    $policy = prPolicy(['required_on_hire' => ['grade_id']]);

    $version = $this->preflight->switchToBlock($policy, ['required'], $this->admin, 'Block');
    $run = Run::query()->sole();
    expect($run->only(['purpose', 'mode', 'status']))->toBe(['purpose' => 'switch', 'mode' => 'sync', 'status' => 'completed'])
        ->and($version->settings['preflight']['run'])->toBe($run->ulid)
        ->and($run->report['employees'])->toBe(1);
});

it('queues the pre-flight above the threshold, and only a completed, current and recent run lets Block through', function () {
    config(['peopleos.employment_requirements.preflight_queue_threshold' => 1]);
    prHire($this->mk, ['grade_id' => $this->grade->id]);
    prHire($this->fe, ['grade_id' => $this->grade->id]);
    $policy = prPolicy(['required_on_hire' => ['grade_id']]);
    Queue::fake();

    $run = $this->preflight->record($policy, $this->admin);
    expect($run->only(['mode', 'status']))->toBe(['mode' => 'queued', 'status' => 'pending']);
    Queue::assertPushed(RunEmploymentRequirementsPreflight::class, fn ($job) => $job->runId === $run->id && $job->tenantId() === $this->tenant->id);
    // Idempotent: asked again while open, the same run, no second job.
    expect($this->preflight->record($policy, $this->admin)->id)->toBe($run->id);
    Queue::assertPushed(RunEmploymentRequirementsPreflight::class, 1);
    // Pending authorises nothing.
    expect(fn () => $this->preflight->switchToBlock($policy, ['required'], $this->admin, 'Block'))->toThrow(Exception::class, 'runs in the background');

    (new RunEmploymentRequirementsPreflight($run->id))->handle($this->preflight);
    $run->refresh();
    expect($run->status)->toBe(Run::COMPLETED)->and($run->processed)->toBe($run->total)->and($run->report['employees'])->toBe(2);
    // A retry of a finished run does nothing.
    (new RunEmploymentRequirementsPreflight($run->id))->handle($this->preflight);
    expect($run->fresh()->finished_at->equalTo($run->finished_at))->toBeTrue();

    $version = $this->preflight->switchToBlock($policy, ['required'], $this->admin, 'Block after the queued pre-flight');
    expect($version->settings['preflight']['run'])->toBe($run->ulid)
        ->and($version->settings['preflight']['company_fingerprints'])->toHaveKeys([$this->mk->id, $this->fe->id]);

    // Older than the evidence window: no longer enough.
    $this->travel(25)->hours();
    expect(fn () => $this->preflight->switchToBlock($policy, ['grade_level'], $this->admin, 'More'))->toThrow(Exception::class, 'runs in the background');
});

it('supersedes a queued run when the companies\' own values change while it waits, or a newer run replaces it', function () {
    config(['peopleos.employment_requirements.preflight_queue_threshold' => 1]);
    prHire($this->mk, ['grade_id' => $this->grade->id]);
    prHire($this->fe, ['grade_id' => $this->grade->id]);
    $policy = prPolicy(['required_on_hire' => ['grade_id']]);
    Queue::fake();
    $run = $this->preflight->record($policy, $this->admin);

    app(ConfigurationOverrides::class)->set($policy, $this->fe->id, 'required_on_change', ['grade_id'], '2026-11-25', $this->admin, 'FynnEdge changes its rules meanwhile');
    $this->preflight->execute($run);
    expect($run->fresh()->status)->toBe(Run::SUPERSEDED)->and($run->fresh()->error)->toContain('changed while it ran')
        ->and(fn () => $this->preflight->switchToBlock($policy, ['required'], $this->admin, 'Block'))->toThrow(Exception::class, 'runs in the background');

    // A new request for other settings supersedes the open one.
    $first = $this->preflight->record($policy, $this->admin);
    $second = $this->preflight->record($policy, $this->admin, Run::SWITCH, ['required_on_hire' => ['grade_id'], 'required_mode' => 'block']);
    expect($first->fresh()->status)->toBe(Run::PENDING)   // another purpose: left alone
        ->and($this->preflight->record($policy, $this->admin, Run::SWITCH, ['required_on_hire' => [], 'required_mode' => 'warn'])->id)->not->toBe($second->id)
        ->and($second->fresh()->status)->toBe(Run::SUPERSEDED);
});

it('stops a run cancelled while it reads, retries a failed attempt, and marks the last one failed', function () {
    config(['peopleos.employment_requirements.preflight_queue_threshold' => 1]);
    prHire($this->mk);
    prHire($this->fe);
    $policy = prPolicy(['required_on_hire' => ['grade_id']]);
    Queue::fake();

    // Cancelled while it reads: it stops between batches, keeps no result.
    $run = $this->preflight->record($policy, $this->admin);
    Event::listen('eloquent.retrieved: '.Employee::class, fn () => Run::query()->whereKey($run->id)->where('status', Run::RUNNING)->update(['status' => Run::CANCELLED]));
    $this->preflight->execute($run);
    expect($run->fresh()->status)->toBe(Run::CANCELLED)->and($run->fresh()->report)->toBeNull();
    Event::forget('eloquent.retrieved: '.Employee::class);

    // A failing attempt goes back to pending for the retry; the last attempt marks it failed.
    $failing = $this->preflight->record($policy, $this->admin, Run::SWITCH, ['required_on_hire' => ['grade_id'], 'required_mode' => 'block']);
    Event::listen('eloquent.retrieved: '.Employee::class, fn () => throw new RuntimeException('database went away'));
    expect(fn () => $this->preflight->execute($failing, final: false))->toThrow(RuntimeException::class, 'database went away');
    expect($failing->fresh()->status)->toBe(Run::PENDING);
    $this->preflight->execute($failing->fresh(), final: true);
    expect($failing->fresh()->status)->toBe(Run::FAILED)->and($failing->fresh()->error)->toContain('database went away');
});

it('keeps runs to their tenant, and runs a queued one as the person who asked (examples within their access, counts of everyone)', function () {
    config(['peopleos.employment_requirements.preflight_queue_threshold' => 1]);
    prHire($this->mk);
    $theirs = prHire($this->fe);
    $policy = prPolicy(['required_on_hire' => ['grade_id'], 'required_on_change' => ['grade_id']]);
    $limited = tenantUser($this->tenant, ['policy.view']);
    app(AccessScopes::class)->assign($limited, ['company' => [$this->mk->id]]);
    Queue::fake();
    $run = $this->preflight->record($policy, $limited->fresh());

    auth()->logout();
    (new RunEmploymentRequirementsPreflight($run->id))->handle($this->preflight);
    $report = $run->fresh()->report;
    expect($report['rules']['required']['violations'])->toBe(2)
        ->and(collect($report['rules']['required']['samples'])->filter(fn ($s) => str_starts_with($s, $theirs->employee_code.' ')))->toBeEmpty()
        ->and(auth()->user())->toBeNull();

    $other = provisionTenant('Elsewhere');
    actAsTenant($other);
    expect(Run::query()->count())->toBe(0);
});

it('follows a queued pre-flight on the page, and runs it in the request below the threshold as before', function () {
    prHire($this->mk, ['grade_id' => $this->grade->id]);
    $policy = prPolicy(['required_on_hire' => ['grade_id']]);
    Livewire::test(EmploymentRequirementsPage::class)->assertOk()->assertSee('Pre-flight on')->assertDontSee('Pre-flight in the background');

    config(['peopleos.employment_requirements.preflight_queue_threshold' => 0]);
    Queue::fake();
    Livewire::test(EmploymentRequirementsPage::class)->assertOk()->assertDontSee('Pre-flight on')
        ->callAction('rerun')->assertNotified('Pre-flight queued')->assertSee('Pre-flight in the background')->assertSee('Waiting to start');
    $run = Run::query()->sole();
    (new RunEmploymentRequirementsPreflight($run->id))->handle($this->preflight);
    Livewire::test(EmploymentRequirementsPage::class)->assertSee('Completed')->assertSee('Pre-flight on');
});
