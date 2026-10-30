<?php

use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Department;
use App\Domain\Performance\Models\Initiative;
use App\Domain\Performance\Models\InitiativeFeedback;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\Initiatives;
use App\Domain\Performance\Support\InitiativeFeedbackTaskSource;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Resources\Initiatives\Pages\ManageInitiatives;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity HP-8j — Initiatives: an employee's initiatives of an initiative type (performance masters), linked to
 * one of their goals or not, with dates, completion and status; assigned by their performance managers or HR; feedback
 * asked of colleagues (answered once, or ignored) or given by the manager; notifications; HR list; report dataset. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.goals', 'performance.initiatives']);
    $this->employee = activeEmployee($this->manager, ['performance.goals', 'performance.initiatives', 'task.view']);
    $this->peer = activeEmployee($this->manager, ['performance.goals', 'performance.initiatives', 'task.view']);
    $this->outsider = activeEmployee(null, ['performance.goals', 'performance.initiatives', 'task.view']);
    $this->initiatives = app(Initiatives::class);
    $this->buddy = PerformanceLookup::query()->ofKind('initiative_type')->where('code', 'BUDDY')->firstOrFail();
});

function hp8jTitles($user): array
{
    return $user->notifications()->get()->pluck('data.title')->all();
}

function hp8jData(array $overrides = []): array
{
    return array_merge(['title' => 'Buddy for the new joiners', 'initiative_type_id' => PerformanceLookup::query()->ofKind('initiative_type')->where('code', 'BUDDY')->value('id'),
        'description' => 'Show them around for the first month', 'start_date' => '2026-09-21', 'due_date' => '2026-10-31'], $overrides);
}

it('declares the permissions, role templates, masters and inventory the initiatives need', function () {
    expect(config('peopleos.permissions.performance'))->toHaveKeys(['performance.initiatives', 'performance.initiatives_manage'])
        ->and(config('peopleos.roles.employee.permissions'))->toContain('performance.initiatives')
        ->and(config('peopleos.roles.manager.permissions'))->toContain('performance.initiatives')
        ->and(config('peopleos.roles.employee.permissions'))->not->toContain('performance.initiatives_manage')
        ->and(config('peopleos.roles.tenant-hr-admin.permissions'))->toContain('performance.*')
        ->and(DataInventory::tableCategories())->toMatchArray(['initiatives' => 'performance', 'initiative_feedback' => 'performance'])
        ->and(PerformanceLookup::codeOptions('initiative_type'))->toHaveKeys(['BUDDY', 'INDUCTION', 'INITIATIVE', 'PIP', 'TNI'])
        ->and(Initiative::query()->count())->toBe(0);
});

it('lets an employee create and track their own initiative, linked to their goal; their managers are told', function () {
    $goal = app(Goals::class)->create(['employee_id' => $this->employee->id, 'title' => 'Onboarding quality', 'weight' => 100]);
    $other = app(Goals::class)->create(['employee_id' => $this->peer->id, 'title' => 'Peer goal', 'weight' => 100]);
    $me = $this->employee->user;

    expect(fn () => $this->initiatives->create($this->employee, hp8jData(['initiative_type_id' => null]), $me))->toThrow(RuntimeException::class, 'Choose the initiative type')
        ->and(fn () => $this->initiatives->create($this->employee, hp8jData(['goal_id' => $other->id]), $me))->toThrow(RuntimeException::class, 'own goals')
        ->and(fn () => $this->initiatives->create($this->employee, hp8jData(['due_date' => '2026-09-01']), $me))->toThrow(RuntimeException::class, 'due before it starts')
        ->and(fn () => $this->initiatives->create($this->peer, hp8jData(), $me))->toThrow(RuntimeException::class, 'cannot create an initiative for this employee');

    $initiative = $this->initiatives->create($this->employee, hp8jData(['goal_id' => $goal->id]), $me);
    expect($initiative->status)->toBe('planned')->and($initiative->owner_id)->toBeNull()->and($initiative->goal_id)->toBe($goal->id)
        ->and($initiative->created_by)->toBe($me->id)
        ->and(collect(hp8jTitles($this->manager->user))->contains(fn ($t) => str_contains($t, 'created an initiative: Buddy for the new joiners')))->toBeTrue();

    $initiative = $this->initiatives->update($initiative, ['progress' => 40], $me);
    expect($initiative->status)->toBe('in_progress')->and($initiative->progress)->toBe(40)
        ->and(fn () => $this->initiatives->update($initiative, ['progress' => 140], $me))->toThrow(RuntimeException::class, 'between 0 and 100');

    $initiative = $this->initiatives->complete($initiative, 'Both joiners settled in', $me);
    expect($initiative->status)->toBe('completed')->and($initiative->progress)->toBe(100)->and($initiative->completed_at)->not->toBeNull()
        ->and(collect(hp8jTitles($this->manager->user))->contains(fn ($t) => str_contains($t, 'Initiative completed by')))->toBeTrue()
        ->and(fn () => $this->initiatives->update($initiative, ['title' => 'Changed'], $me))->toThrow(RuntimeException::class, 'cannot change this initiative')
        ->and(fn () => $initiative->update(['title' => 'Forced']))->toThrow(RuntimeException::class, 'read-only')
        ->and(fn () => $initiative->delete())->toThrow(RuntimeException::class, 'never deleted');

    $second = $this->initiatives->create($this->employee, hp8jData(['title' => 'Induction talk']), $me);
    expect(fn () => $this->initiatives->cancel($second, '  ', $me))->toThrow(RuntimeException::class, 'reason')
        ->and($this->initiatives->cancel($second, 'Joiner postponed', $me)->status)->toBe('cancelled');
});

it('lets managers assign initiatives to the people they manage (who are told), never to others', function () {
    $initiative = $this->initiatives->create($this->employee, hp8jData(), $this->manager->user);

    expect($initiative->owner_id)->toBe($this->manager->id)
        ->and(collect(hp8jTitles($this->employee->user))->contains(fn ($t) => str_contains($t, 'New initiative for you: Buddy for the new joiners')))->toBeTrue()
        ->and(fn () => $this->initiatives->create($this->outsider, hp8jData(), $this->manager->user))->toThrow(RuntimeException::class, 'cannot create an initiative')
        ->and(fn () => $this->initiatives->start($initiative, $this->outsider->user))->toThrow(RuntimeException::class, 'Only a planned initiative')
        ->and($this->initiatives->start($initiative, $this->manager->user)->status)->toBe('in_progress');

    // Without performance.initiatives a manager by relationship alone cannot.
    $bare = activeEmployee(null, ['performance.team']);
    $report = activeEmployee($bare);
    expect(fn () => $this->initiatives->create($report, hp8jData(), $bare->user))->toThrow(RuntimeException::class, 'cannot create an initiative');
});

it('lets HR manage initiatives within their access scope only (fail closed)', function () {
    $hr = tenantUser($this->tenant, ['performance.initiatives_manage']);
    $initiative = $this->initiatives->create($this->outsider, hp8jData(), $hr);
    expect($initiative->owner_id)->toBeNull()->and($initiative->created_by)->toBe($hr->id)
        ->and($this->initiatives->mayView($hr, $initiative))->toBeTrue();

    $scoped = tenantUser($this->tenant, ['performance.initiatives_manage']);
    UserAccessScope::query()->create(['user_id' => $scoped->id, 'dimension' => 'department', 'scope_id' => Department::factory()->create()->id]);
    app(AccessScopes::class)->forget();
    expect(fn () => $this->initiatives->create($this->outsider, hp8jData(), $scoped))->toThrow(RuntimeException::class, 'cannot create an initiative')
        ->and($this->initiatives->mayView($scoped, $initiative))->toBeFalse()
        ->and($this->initiatives->mayEdit($scoped, $initiative))->toBeFalse();

    // Read-only HR (performance.view) sees it, cannot change it.
    $viewer = tenantUser($this->tenant, ['performance.view']);
    expect($this->initiatives->mayView($viewer, $initiative))->toBeTrue()->and($this->initiatives->mayEdit($viewer, $initiative))->toBeFalse();
});

it('asks colleagues for feedback (a task for them), takes their answer once, and lets the manager give feedback', function () {
    $initiative = $this->initiatives->create($this->employee, hp8jData(), $this->employee->user);
    $request = $this->initiatives->requestFeedback($initiative, $this->peer, $this->employee->user);

    expect($request->status)->toBe('requested')
        ->and(fn () => $this->initiatives->requestFeedback($initiative, $this->peer, $this->employee->user))->toThrow(RuntimeException::class, 'already asked')
        ->and(fn () => $this->initiatives->requestFeedback($initiative, $this->employee, $this->employee->user))->toThrow(RuntimeException::class, 'someone other than the employee')
        ->and(fn () => $this->initiatives->requestFeedback($initiative, $this->manager, $this->outsider->user))->toThrow(RuntimeException::class, 'cannot ask for feedback')
        ->and(collect(hp8jTitles($this->peer->user))->contains(fn ($t) => str_contains($t, 'Your feedback is asked on')))->toBeTrue();

    $tasks = app(InitiativeFeedbackTaskSource::class)->tasksFor($this->peer->user, $this->peer);
    expect($tasks)->toHaveCount(1)->and($tasks->first()->kind)->toBe('initiative_feedback')->and($tasks->first()->dueAt)->toBeNull();

    expect(fn () => $this->initiatives->respond($request, 'Not mine to give', $this->outsider->user))->toThrow(RuntimeException::class, 'Only the colleague who was asked')
        ->and(fn () => $this->initiatives->respond($request, ' ', $this->peer->user))->toThrow(RuntimeException::class, 'Write your feedback');
    $given = $this->initiatives->respond($request, 'Very welcoming, great checklists', $this->peer->user);
    expect($given->status)->toBe('given')->and($given->responded_at)->not->toBeNull()
        ->and(fn () => $this->initiatives->respond($request->refresh(), 'Again', $this->peer->user))->toThrow(RuntimeException::class, 'already answered')
        ->and(app(InitiativeFeedbackTaskSource::class)->tasksFor($this->peer->user, $this->peer))->toHaveCount(0)
        ->and(collect(hp8jTitles($this->employee->user))->contains(fn ($t) => str_contains($t, 'gave feedback on the initiative')))->toBeTrue()
        // The feedback text never travels in the notification.
        ->and(collect(hp8jTitles($this->employee->user))->contains(fn ($t) => str_contains($t, 'welcoming')))->toBeFalse();

    // An ignored request closes without feedback; the employee never gives feedback on their own initiative.
    $ignored = $this->initiatives->ignore($this->initiatives->requestFeedback($initiative, $this->outsider, $this->manager->user), $this->outsider->user);
    expect($ignored->status)->toBe('ignored')->and($ignored->feedback)->toBeNull()
        ->and(fn () => $this->initiatives->giveFeedback($initiative, 'Self praise', $this->employee->user))->toThrow(RuntimeException::class, 'cannot give feedback');
    $this->initiatives->giveFeedback($initiative, 'Keep it up', $this->manager->user);

    // Who reads what: the employee and the manager everything; a colleague only their own.
    expect($this->initiatives->feedbackVisibleTo($this->employee->user, $initiative)->pluck('status')->all())->toBe(['given', 'ignored', 'given'])
        ->and($this->initiatives->feedbackVisibleTo($this->peer->user, $initiative)->pluck('reviewer_id')->unique()->all())->toBe([$this->peer->id])
        ->and(fn () => $given->update(['feedback' => 'Rewritten']))->toThrow(RuntimeException::class, 'already given')
        ->and(fn () => $given->delete())->toThrow(RuntimeException::class, 'never deleted');
});

it('lists initiatives as each person may see them: own, team, asked about, all for HR; tenants stay apart', function () {
    $own = $this->initiatives->create($this->employee, hp8jData(), $this->employee->user);
    $peers = $this->initiatives->create($this->peer, hp8jData(['title' => 'Peer initiative']), $this->peer->user);
    $outsiders = $this->initiatives->create($this->outsider, hp8jData(['title' => 'Outsider initiative']), $this->outsider->user);
    $this->initiatives->requestFeedback($outsiders, $this->employee, $this->outsider->user);

    $this->actingAs($this->employee->user);
    expect($this->initiatives->visibleTo($this->employee->user)->pluck('id')->sort()->values()->all())->toBe([$own->id, $outsiders->id])
        ->and($this->initiatives->mayView($this->employee->user, $peers))->toBeFalse();
    $this->actingAs($this->manager->user);
    expect($this->initiatives->visibleTo($this->manager->user)->pluck('id')->sort()->values()->all())->toBe([$own->id, $peers->id]);
    $this->actingAs($this->hr);
    expect($this->initiatives->visibleTo($this->hr)->count())->toBe(3);
    $nobody = tenantUser($this->tenant, ['task.view']);
    expect($this->initiatives->visibleTo($nobody)->count())->toBe(0)->and(InitiativeResource::canAccess())->toBeTrue();

    $other = provisionTenant('Other');
    actAsTenant($other);
    expect(Initiative::query()->count())->toBe(0)->and(InitiativeFeedback::query()->count())->toBe(0);
    actAsTenant(null);
    expect(Initiative::query()->count())->toBe(0);
});

it('runs the screens: create from "My initiatives", answer a request, HR list and the report dataset', function () {
    $this->actingAs($this->employee->user);
    Livewire::test(ManageInitiatives::class)->assertOk()
        ->callAction('create', data: ['employee_id' => $this->employee->id, 'initiative_type_id' => $this->buddy->id, 'title' => 'Mentor two interns'])
        ->assertHasNoActionErrors();
    $initiative = Initiative::query()->where('title', 'Mentor two interns')->firstOrFail();
    expect($initiative->employee_id)->toBe($this->employee->id)->and(InitiativeResource::getNavigationGroup())->toBe('Me')
        ->and(InitiativeResource::getNavigationLabel())->toBe('My initiatives');

    Livewire::test(ManageInitiatives::class)
        ->callTableAction('requestFeedback', $initiative, ['reviewer_id' => $this->peer->id])->assertNotified('Feedback requested');

    $this->actingAs($this->peer->user);
    Livewire::test(ManageInitiatives::class, ['activeTab' => 'feedback'])->assertCanSeeTableRecords([$initiative])
        ->callTableAction('respond', $initiative, ['feedback' => 'Patient and clear'])->assertNotified('Feedback sent');
    expect(InitiativeFeedback::query()->where('initiative_id', $initiative->id)->value('status'))->toBe('given');

    $this->actingAs($this->hr);
    expect(InitiativeResource::getNavigationGroup())->toBe('Performance');
    $result = app(ReportRunner::class)->execute('initiatives', app(ReportLibrary::class)->find('initiative_details')['definition'], $this->hr);
    expect($result->rows)->toHaveCount(1)->and($result->rows[0])->toMatchArray(['title' => 'Mentor two interns', 'initiative_type' => 'BUDDY', 'status' => 'planned', 'feedback_given' => 1]);

    actAsTenant(null);
    $this->actingAs($this->employee->user);
    $this->get(InitiativeResource::getUrl('index'))->assertOk()->assertSee('Mentor two interns');
    $this->actingAs($this->outsider->user);
    $this->get(InitiativeResource::getUrl('index'))->assertOk()->assertDontSee('Mentor two interns');
});
