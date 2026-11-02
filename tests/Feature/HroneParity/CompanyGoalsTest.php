<?php

use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Department;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalAlignment;
use App\Domain\Performance\Models\GoalCheckIn;
use App\Domain\Performance\Models\GoalViewer;
use App\Domain\Performance\Scopes\GoalVisibilityScope;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\KpiRatings;
use App\Domain\Performance\Support\GoalVisibility;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Resources\Goals\RelationManagers\LinkedGoalsRelationManager;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne parity GO-4 — company goals and OKR depth: the owner person of an organisation goal, the goal status (health) and
 * the period of a check-in, private goals with a visible-to list (owner decision PER-5 / D-GO5: owner, creator, the list
 * and HR in scope; owners of aligned goals see only the title), tags, the linked (aligned) goals with linked / unlinked on,
 * and the actual achievement overriding the system's only while `performance.goals.achievement_override` is on (PER-6 /
 * D-GO7: HR or the owner's manager, with a reason, the system value kept beside it, audited). */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    Filament::getPanel('admin')->boot();
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->goals = app(Goals::class);
    $this->settings = app(SettingsRepository::class);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.goals', 'performance.review']);
    $this->employee = activeEmployee($this->manager);
    $this->owner = activeEmployee();
    $this->viewer = activeEmployee();
    $this->outsider = activeEmployee();
    $this->sales = Department::factory()->create(['name' => 'Sales']);
    EmployeePosition::query()->where('employee_id', $this->owner->id)->update(['department_id' => $this->sales->id]);
});

/** A private company goal owned by $this->owner, visible to $this->viewer, created by HR. */
function go4Private(string $title = 'Secret acquisition', array $attributes = []): Goal
{
    return app(Goals::class)->create(['level' => 'company', 'title' => $title, 'owner_employee_id' => test()->owner->id, 'visibility' => 'private', 'visible_to' => [test()->viewer->id], ...$attributes]);
}

/** Performance HR whose access scope is one department. */
function go4ScopedHr(Department $department): User
{
    $user = tenantUser(test()->tenant, ['performance.view', 'performance.manage', 'performance.goals']);
    app(AccessScopes::class)->assign($user, ['department' => [$department->id]], 'Sales HR');

    return $user->fresh();
}

/** @return list<int> ids of the goals the signed-in user's queries return */
function go4VisibleIds(): array
{
    return Goal::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

it('keeps today\'s behaviour by default: public goals, no owner, final achievement = system, check-ins as before', function () {
    expect($this->settings->get('performance.goals.achievement_override'))->toBeFalse()
        ->and(json_encode(config('peopleos_setup')))->toContain('performance.goals.achievement_override');

    $company = $this->goals->create(['level' => 'company', 'title' => 'Grow ARR']);
    expect($company->visibility)->toBe('public')->and($company->owner_employee_id)->toBeNull()->and($company->health)->toBeNull()
        ->and($company->healthKey())->toBe('not_started')->and($company->tags)->toBeNull()->and($company->viewers()->count())->toBe(0);

    $mine = $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Mine', 'parent_id' => $company->id, 'weight' => 50]);
    $checkIn = $this->goals->checkIn($mine, 40, 'Halfway', 'on_track');
    expect($checkIn->health)->toBeNull()->and($checkIn->period_start)->toBeNull()->and($checkIn->periodLabel())->toBeNull()
        ->and($mine->refresh()->health)->toBeNull()
        ->and($mine->systemAchievement())->toBe(40.0)->and($mine->finalAchievement())->toBe(40.0)->and($mine->achievementOverridden())->toBeFalse()
        ->and((float) $company->refresh()->progress)->toBe(40.0)
        ->and(app(KpiRatings::class)->achievementPct($mine, 'achievement_over_target'))->toBe(40.0);

    // Everyone still sees organisation goals; the policy is unchanged for public goals.
    $this->actingAs($this->outsider->user);
    expect(go4VisibleIds())->toContain($company->id)
        ->and($this->outsider->user->can('update', $company))->toBeFalse()->and($this->outsider->user->can('checkIn', $company))->toBeFalse()
        ->and($this->employee->user->can('checkIn', $mine))->toBeTrue();
    Livewire::test(ListGoals::class)->assertCanSeeTableRecords([$company]);

    // Overriding the achievement is switched off: refused, and the action is not offered.
    $this->actingAs($this->hr);
    expect(fn () => $this->goals->overrideAchievement($mine, 80, 'Too low', $this->hr))->toThrow(RuntimeException::class, 'switched off');
    Livewire::test(ListGoals::class)->assertTableActionHidden('overrideAchievement', $mine)->assertTableActionVisible('checkIn', $mine);
});

it('gives an organisation goal an owner person who sees it and checks in with a goal status and a period', function () {
    $goal = $this->goals->create(['level' => 'company', 'title' => 'Win 20 logos', 'owner_employee_id' => $this->owner->id, 'measure_type' => 'numeric', 'target_value' => 20]);
    expect($goal->ownerEmployee->is($this->owner))->toBeTrue()
        ->and(fn () => $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Mine', 'owner_employee_id' => $this->owner->id]))->toThrow(RuntimeException::class, 'Only organisation goals have an owner person')
        ->and(fn () => $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Mine', 'visibility' => 'private']))->toThrow(RuntimeException::class, 'Only organisation goals');

    // The owner may check in (not edit); others may not.
    expect($this->owner->user->can('checkIn', $goal))->toBeTrue()->and($this->owner->user->can('update', $goal))->toBeFalse()
        ->and($this->outsider->user->can('checkIn', $goal))->toBeFalse();
    $this->actingAs($this->owner->user);
    Livewire::test(ListGoals::class)
        ->callTableAction('checkIn', $goal, data: ['value' => 5, 'health' => 'behind', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'note' => 'Slow quarter'])
        ->assertHasNoTableActionErrors();
    $checkIn = GoalCheckIn::query()->where('goal_id', $goal->id)->sole();
    expect($checkIn->health)->toBe('behind')->and($checkIn->period_start->toDateString())->toBe('2026-09-01')
        ->and($checkIn->periodLabel())->toBe('01 Sep 2026 – 30 Sep 2026')->and($checkIn->created_by)->toBe($this->owner->user_id)
        ->and($goal->refresh()->health)->toBe('behind')->and($goal->healthLabel())->toBe('Behind')->and((float) $goal->progress)->toBe(25.0);

    // Refusals; a check-in without a status keeps the goal's.
    expect(fn () => $this->goals->checkIn($goal, 6, health: 'great'))->toThrow(RuntimeException::class, 'Unknown goal status')
        ->and(fn () => $this->goals->checkIn($goal, 6, periodStart: '2026-10-31', periodEnd: '2026-10-01'))->toThrow(RuntimeException::class, 'cannot end before it starts');
    $this->goals->checkIn($goal, 8);
    expect($goal->refresh()->health)->toBe('behind');

    // Owner and goal status filters: "not started" = open goals nobody reported on, "closed" = completed or cancelled.
    $this->actingAs($this->hr);
    $other = $this->goals->create(['level' => 'company', 'title' => 'Other']);
    $done = $this->goals->create(['level' => 'company', 'title' => 'Done']);
    $this->goals->close($done, 'completed');
    Livewire::test(ListGoals::class)
        ->filterTable('owner_employee_id', $this->owner->id)->assertCanSeeTableRecords([$goal])->assertCanNotSeeTableRecords([$other, $done])
        ->resetTableFilters()->filterTable('health', 'behind')->assertCanSeeTableRecords([$goal])->assertCanNotSeeTableRecords([$other, $done])
        ->resetTableFilters()->filterTable('health', 'not_started')->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$goal, $done])
        ->resetTableFilters()->filterTable('health', 'closed')->assertCanSeeTableRecords([$done])->assertCanNotSeeTableRecords([$goal, $other]);
    expect($done->refresh()->healthLabel())->toBe('Closed');

    // Dataset columns: owner, goal status; the check-ins dataset carries the status and the period.
    $rows = collect(app(ReportRunner::class)->execute('goals', ['fields' => ['title', 'owner', 'owner_code', 'health', 'health_key', 'visibility']], $this->hr)->rows)->keyBy('title');
    expect($rows['Win 20 logos'])->toMatchArray(['owner' => $this->owner->person->full_name, 'owner_code' => $this->owner->employee_code, 'health' => 'Behind', 'health_key' => 'behind', 'visibility' => 'public'])
        ->and($rows['Other']['health_key'])->toBe('not_started');
    $checkIns = collect(app(ReportRunner::class)->execute('check_ins', ['fields' => ['goal', 'health', 'period_start', 'period_end']], $this->hr)->rows);
    expect($checkIns->firstWhere('health', 'Behind'))->not->toBeNull();

    // Turning an organisation goal into an employee goal drops the owner person and makes it public.
    $this->goals->update($other, ['owner_employee_id' => $this->owner->id, 'visibility' => 'private']);
    $this->goals->update($other->refresh(), ['level' => 'employee', 'employee_id' => $this->employee->id]);
    expect($other->refresh()->owner_employee_id)->toBeNull()->and($other->visibility)->toBe('public');
});

it('keeps a private goal to its owner, creator, visible-to list and HR in scope on the list, search, policy and form', function () {
    $private = go4Private();
    $public = $this->goals->create(['level' => 'company', 'title' => 'Public growth']);
    expect(GoalViewer::query()->where('goal_id', $private->id)->pluck('employee_id')->all())->toBe([$this->viewer->id]);

    foreach ([$this->owner, $this->viewer] as $person) {
        $this->actingAs($person->user);
        expect(go4VisibleIds())->toContain($private->id)->and($person->user->can('view', $private) || $person->user->can('checkIn', $private) || GoalVisibility::canSee($person->user, $private))->toBeTrue();
        Livewire::test(ListGoals::class)->assertCanSeeTableRecords([$private, $public])->searchTable('Secret')->assertCanSeeTableRecords([$private]);
    }
    foreach ([$this->outsider, $this->employee, $this->manager] as $person) {
        $this->actingAs($person->user);
        expect(go4VisibleIds())->toContain($public->id)->not->toContain($private->id)
            ->and(Goal::query()->find($private->id))->toBeNull()
            ->and(GoalVisibility::canSee($person->user, $private))->toBeFalse()
            ->and($person->user->can('view', $private))->toBeFalse()->and($person->user->can('checkIn', $private))->toBeFalse();
        Livewire::test(ListGoals::class)->assertCanSeeTableRecords([$public])->assertCanNotSeeTableRecords([$private])
            ->searchTable('Secret')->assertCanNotSeeTableRecords([$private])
            ->filterTable('visibility', 'private')->assertCanNotSeeTableRecords([$private]);
        // "Aligned to" never offers a private goal one may not see.
        Livewire::test(CreateGoal::class)->assertFormFieldExists('parent_id', fn (Select $field) => array_key_exists($public->id, $field->getOptions()) && ! array_key_exists($private->id, $field->getOptions()));
    }
    // The page itself is closed to them (resolved through the same query).
    $this->actingAs($this->outsider->user);
    $this->get(GoalResource::getUrl('edit', ['record' => $private]))->assertNotFound();

    // Unscoped HR sees it, and so does the owner of the visible-to list's edit form.
    $this->actingAs($this->hr);
    expect(go4VisibleIds())->toContain($private->id);
    Livewire::test(EditGoal::class, ['record' => $private->id])->assertOk()
        ->assertFormSet(['visibility' => 'private', 'visible_to' => [$this->viewer->id], 'owner_employee_id' => $this->owner->id]);

    // The visible-to list changes with the goal; each change is audited.
    $this->goals->update($private, ['visible_to' => [$this->outsider->id]], reason: 'Board member joins');
    $this->actingAs($this->outsider->user);
    expect(go4VisibleIds())->toContain($private->id);
    $this->actingAs($this->viewer->user);
    expect(go4VisibleIds())->not->toContain($private->id);
    $this->actingAs($this->hr);
    expect(AuditEvent::query()->where('entity_id', (string) $private->id)->where('reason', 'Board member joins')->exists())->toBeTrue()
        ->and(fn () => $this->goals->update($private, ['visible_to' => [999999]]))->toThrow(RuntimeException::class, 'visible-to list');
});

it('names a private goal to the owner of a goal aligned to it by title only, rolls progress up and keeps it out of datasets', function () {
    $private = go4Private();
    $child = $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Close the deal', 'parent_id' => $private->id, 'weight' => 40]);

    // The aligned goal's owner: the parent's title, nothing else of it.
    $this->actingAs($this->employee->user);
    expect(GoalVisibility::parentTitleFor($child, $this->employee->user))->toBe('Secret acquisition')
        ->and(Goal::query()->find($private->id))->toBeNull()->and(go4VisibleIds())->toBe([$child->id]);
    Livewire::test(ListGoals::class)->assertCanSeeTableRecords([$child])->assertCanNotSeeTableRecords([$private])->assertSee('↳ Secret acquisition');
    Livewire::test(EditGoal::class, ['record' => $child->id])->assertOk()
        ->assertFormFieldExists('parent_id', fn (Select $field) => ($field->getOptions()[$private->id] ?? null) === 'Secret acquisition');

    // Progress still rolls up into the private goal, and closing the aligned goal works.
    $this->goals->checkIn($child, 50, actor: $this->employee->user);
    $parent = fn () => Goal::query()->withoutGlobalScope(GoalVisibilityScope::class)->find($private->id);
    expect((float) $parent()->progress)->toBe(50.0);
    $this->goals->close($child->refresh(), 'completed');
    expect((float) $parent()->progress)->toBe(100.0);

    // Their manager sees the aligned goal but not whose goal it is aligned to.
    $this->actingAs($this->manager->user);
    expect(GoalVisibility::parentTitleFor($child, $this->manager->user))->toBe(GoalVisibility::HIDDEN_PARENT);
    Livewire::test(ListGoals::class)->assertCanSeeTableRecords([$child])->assertSee('↳ '.GoalVisibility::HIDDEN_PARENT)->assertDontSee('Secret acquisition');

    // Datasets: performance HR outside the owner's scope sees neither the goal nor its check-ins.
    $this->actingAs($this->owner->user);
    $this->goals->checkIn($parent(), 60, 'Owner update', actor: $this->owner->user);
    $marketing = Department::factory()->create(['name' => 'Marketing']);
    $other = go4ScopedHr($marketing);
    $this->actingAs($other);
    $runner = app(ReportRunner::class);
    expect(collect($runner->execute('goals', ['fields' => ['title']], $other)->rows)->pluck('title'))->not->toContain('Secret acquisition')
        ->and(collect($runner->execute('check_ins', ['fields' => ['goal', 'note']], $other)->rows)->pluck('note'))->not->toContain('Owner update');
    // Unscoped HR: the goal, its owner, the aligned goal's parent and the linked count.
    $this->actingAs($this->hr);
    $rows = collect($runner->execute('goals', ['fields' => ['title', 'visibility', 'aligned_to', 'linked_goals', 'owner']], $this->hr)->rows)->keyBy('title');
    expect($rows['Secret acquisition'])->toMatchArray(['visibility' => 'private', 'linked_goals' => 1, 'owner' => $this->owner->person->full_name])
        ->and($rows['Close the deal']['aligned_to'])->toBe('Secret acquisition')
        ->and(collect($runner->execute('check_ins', ['fields' => ['note']], $this->hr)->rows)->pluck('note'))->toContain('Owner update');
});

it('shows private goals to performance HR only within their access scope, and to their creator', function () {
    $owned = go4Private('Sales secret');                                                        // owner in Sales
    $elsewhere = go4Private('Other secret', ['owner_employee_id' => $this->outsider->id]);       // owner outside Sales
    $ownerless = go4Private('Ownerless secret', ['owner_employee_id' => null]);
    $public = $this->goals->create(['level' => 'company', 'title' => 'Public growth']);
    $hiddenChild = go4Private('Hidden business', ['level' => 'business', 'parent_id' => $public->id, 'owner_employee_id' => $this->outsider->id]);

    $salesHr = go4ScopedHr($this->sales);
    $this->actingAs($salesHr);
    $mine = $this->goals->create(['level' => 'company', 'title' => 'My own secret', 'visibility' => 'private'], [], $salesHr);
    expect(go4VisibleIds())->toContain($owned->id, $mine->id, $public->id)
        ->and(go4VisibleIds())->not->toContain($elsewhere->id)->and(go4VisibleIds())->not->toContain($ownerless->id)->and(go4VisibleIds())->not->toContain($hiddenChild->id)
        ->and(GoalVisibility::canSee($salesHr, $owned))->toBeTrue()->and(GoalVisibility::canSee($salesHr, $elsewhere))->toBeFalse()
        ->and(GoalVisibility::canSee($salesHr, $mine))->toBeTrue()->and($salesHr->can('update', $elsewhere))->toBeFalse();
    Livewire::test(ListGoals::class)->assertCanSeeTableRecords([$owned, $mine, $public])->assertCanNotSeeTableRecords([$elsewhere, $ownerless, $hiddenChild]);
    // The linked goals of a public goal leave out the private goal aligned to it.
    Livewire::test(LinkedGoalsRelationManager::class, ['ownerRecord' => $public, 'pageClass' => EditGoal::class])
        ->assertCanNotSeeTableRecords([GoalAlignment::query()->where('goal_id', $hiddenChild->id)->sole()]);

    // Unscoped HR sees every goal and every linked goal.
    $this->actingAs($this->hr);
    expect(go4VisibleIds())->toContain($owned->id, $elsewhere->id, $ownerless->id, $mine->id, $hiddenChild->id);
    Livewire::test(LinkedGoalsRelationManager::class, ['ownerRecord' => $public, 'pageClass' => EditGoal::class])
        ->assertCanSeeTableRecords([GoalAlignment::query()->where('goal_id', $hiddenChild->id)->sole()]);
});

it('never returns or changes a private goal through the API, and takes the goal status and period there', function () {
    $private = go4Private();
    $public = $this->goals->create(['level' => 'company', 'title' => 'Public growth']);
    expect($private->publicTitle())->toBe(GoalVisibility::HIDDEN_PARENT)->and($public->publicTitle())->toBe('Public growth');
    $read = app(ApiKeys::class)->issue('BI', ['performance.read']);
    $write = app(ApiKeys::class)->issue('OKR tool', ['performance.read', 'performance.write']);

    $ids = collect($this->withHeader('X-Api-Key', $read['plaintext'])->getJson('/api/v1/performance/goals')->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($public->id)->not->toContain($private->id);
    $this->withHeader('X-Api-Key', $read['plaintext'])->getJson("/api/v1/performance/goals/{$private->id}/progress")->assertNotFound();
    $this->withHeader('X-Api-Key', $write['plaintext'])->postJson("/api/v1/performance/goals/{$private->id}/progress", ['value' => 5])->assertNotFound();

    $this->withHeader('X-Api-Key', $write['plaintext'])
        ->postJson("/api/v1/performance/goals/{$public->id}/progress", ['value' => 30, 'health' => 'at_risk', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'])
        ->assertCreated()->assertJsonPath('data.health', 'at_risk')->assertJsonPath('data.period_end', '2026-09-30')->assertJsonPath('data.goal.health', 'at_risk');
    $this->withHeader('X-Api-Key', $write['plaintext'])->postJson("/api/v1/performance/goals/{$public->id}/progress", ['value' => 30, 'health' => 'great'])->assertStatus(422);
    expect(GoalCheckIn::query()->where('goal_id', $private->id)->exists())->toBeFalse();
});

it('tags goals, trims and de-duplicates the tags and filters the list by them', function () {
    $a = $this->goals->create(['level' => 'company', 'title' => 'A', 'tags' => [' Growth ', 'growth', 'FY27', '']]);
    $b = $this->goals->create(['level' => 'company', 'title' => 'B', 'tags' => ['Hiring']]);
    $c = $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'C', 'weight' => 10]);
    expect($a->tags)->toBe(['Growth', 'FY27'])->and($c->tags)->toBeNull()
        ->and(fn () => $this->goals->create(['level' => 'company', 'title' => 'D', 'tags' => [str_repeat('x', 41)]]))->toThrow(RuntimeException::class, 'at most 20 tags');

    Livewire::test(ListGoals::class)
        ->filterTable('tags', ['FY27'])->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c])
        ->resetTableFilters()->filterTable('tags', ['FY27', 'Hiring'])->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$c]);
    expect(GoalResource::tagOptions())->toBe(['FY27' => 'FY27', 'Growth' => 'Growth', 'Hiring' => 'Hiring']);

    $this->goals->update($b, ['tags' => []]);
    expect($b->refresh()->tags)->toBeNull();
    $rows = collect(app(ReportRunner::class)->execute('goals', ['fields' => ['title', 'tags']], $this->hr)->rows)->keyBy('title');
    expect($rows['A']['tags'])->toBe('Growth, FY27')->and($rows['B']['tags'])->toBeNull();

    // The form saves owner, visibility, the visible-to list and tags (organisation goal).
    Livewire::test(CreateGoal::class)
        ->fillForm(['level' => 'company', 'title' => 'Form goal', 'owner_employee_id' => $this->owner->id, 'visibility' => 'private', 'visible_to' => [$this->viewer->id], 'tags' => ['OKR']])
        ->call('create')->assertHasNoFormErrors();
    $created = Goal::query()->where('title', 'Form goal')->sole();
    expect($created->owner_employee_id)->toBe($this->owner->id)->and($created->visibility)->toBe('private')->and($created->tags)->toBe(['OKR'])
        ->and($created->viewers()->pluck('employee_id')->all())->toBe([$this->viewer->id]);
});

it('keeps every alignment as a linked goal with linked on and unlinked on, and lists them on the goal', function () {
    $company = $this->goals->create(['level' => 'company', 'title' => 'Company']);
    $other = $this->goals->create(['level' => 'company', 'title' => 'Other company']);
    $mine = $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Mine', 'parent_id' => $company->id, 'weight' => 40], [['title' => 'KPI one', 'target_value' => 10, 'weight' => 100]]);
    $team = $this->goals->create(['level' => 'team', 'title' => 'Team', 'parent_id' => $company->id, 'owner_employee_id' => $this->owner->id]);
    expect($company->alignments()->whereNull('unlinked_at')->count())->toBe(2);

    $this->travel(1)->days();
    $this->goals->update($mine, ['parent_id' => $other->id]);
    $this->travel(1)->days();
    $this->goals->update($mine->refresh(), ['parent_id' => $company->id]);
    $this->goals->update($team, ['parent_id' => null]);
    $rows = GoalAlignment::query()->where('goal_id', $mine->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows[0]->parent_id)->toBe($company->id)->and($rows[0]->unlinked_at?->toDateString())->toBe('2026-09-22')->and($rows[0]->unlinked_by)->toBe($this->hr->id)
        ->and($rows[1]->parent_id)->toBe($other->id)->and($rows[1]->unlinked_at?->toDateString())->toBe('2026-09-23')
        ->and($rows[2]->parent_id)->toBe($company->id)->and($rows[2]->isLinked())->toBeTrue()
        ->and(GoalAlignment::query()->where('goal_id', $team->id)->sole()->isLinked())->toBeFalse()
        ->and(fn () => $rows[0]->delete())->toThrow(RuntimeException::class, 'never deleted');
    $teamRow = GoalAlignment::query()->where('goal_id', $team->id)->sole();

    Livewire::test(LinkedGoalsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditGoal::class])
        ->assertCanSeeTableRecords([$rows[0], $rows[2], $teamRow])->assertCanNotSeeTableRecords([$rows[1]])
        ->assertSee('KPI one')->assertSee('Still linked')->assertSee($this->owner->person->full_name)
        ->filterTable('linked', 'unlinked')->assertCanSeeTableRecords([$rows[0], $teamRow])->assertCanNotSeeTableRecords([$rows[2]])
        ->resetTableFilters()->searchTable($this->employee->employee_code)->assertCanSeeTableRecords([$rows[2]])->assertCanNotSeeTableRecords([$teamRow])
        ->searchTable('')->callTableAction('checkInLinked', $rows[2], data: ['value' => 5, 'health' => 'on_track', 'note' => 'From the parent'])
        ->assertHasNoTableActionErrors()
        ->assertTableActionHidden('checkInLinked', $rows[0]);
    expect($mine->refresh()->health)->toBe('on_track')
        ->and(view('filament.performance.goal-check-ins', ['checkIns' => LinkedGoalsRelationManager::checkIns($rows[2])])->render())->toContain('On track')->toContain('From the parent');
});

it('overrides the achievement only while switched on, by HR or the owner\'s manager with a reason, keeping the system value and the audit', function () {
    $goal = $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Revenue', 'measure_type' => 'numeric', 'target_value' => 100, 'weight' => 50]);
    $this->goals->checkIn($goal, 40);

    // Off: refused, and a stored override is ignored (final = system).
    expect(fn () => $this->goals->overrideAchievement($goal, 60, 'Market', $this->hr))->toThrow(RuntimeException::class, 'switched off');
    Goal::query()->whereKey($goal->id)->update(['achievement_override' => 90]);
    expect($goal->refresh()->achievementOverridden())->toBeFalse()->and($goal->finalAchievement())->toBe(40.0)
        ->and(app(KpiRatings::class)->achievementPct($goal, 'achievement_over_target'))->toBe(40.0)
        ->and($this->hr->can('overrideAchievement', $goal))->toBeFalse();
    Goal::query()->whereKey($goal->id)->update(['achievement_override' => null]);

    $this->settings->set('performance.goals.achievement_override', true, 'PER-6 decided');
    expect(fn () => $this->goals->overrideAchievement($goal, 60, 'Mine', $this->employee->user))->toThrow(RuntimeException::class, 'never on their own goal')
        ->and(fn () => $this->goals->overrideAchievement($goal, 60, 'Peer', $this->outsider->user))->toThrow(RuntimeException::class, 'Only performance HR')
        ->and(fn () => $this->goals->overrideAchievement($goal, 60, '  ', $this->hr))->toThrow(RuntimeException::class, 'Give a reason')
        ->and(fn () => $this->goals->overrideAchievement($goal, 1200, 'Too much', $this->hr))->toThrow(RuntimeException::class, 'between 0 and 1000')
        ->and($this->manager->user->can('overrideAchievement', $goal))->toBeTrue()->and($this->employee->user->can('overrideAchievement', $goal))->toBeFalse();

    // The manager overrides; the system value stays beside it and the change is audited.
    $this->actingAs($this->manager->user);
    $this->goals->overrideAchievement($goal, 55, 'Market slowdown outside her control', $this->manager->user);
    $goal->refresh();
    expect((float) $goal->achievement_override)->toBe(55.0)->and($goal->systemAchievement())->toBe(40.0)->and($goal->finalAchievement())->toBe(55.0)
        ->and($goal->achievement_overridden_by)->toBe($this->manager->user_id)->and($goal->achievement_override_reason)->toBe('Market slowdown outside her control')
        ->and(app(KpiRatings::class)->achievementPct($goal, 'achievement_over_target'))->toBe(55.0);
    $event = AuditEvent::query()->where('entity_id', (string) $goal->id)->where('reason', 'Market slowdown outside her control')->sole();
    expect((float) $event->metadata['system_achievement'])->toBe(40.0)->and((float) $event->metadata['final_achievement'])->toBe(55.0)
        ->and($event->fieldChanges()->where('field', 'achievement_override')->value('after'))->toContain('55');

    // The system value moves on beside it; HR clears the override from the list (blank = the system's again).
    $this->actingAs($this->hr);
    $this->goals->checkIn($goal, 70);
    expect($goal->refresh()->systemAchievement())->toBe(70.0)->and($goal->finalAchievement())->toBe(55.0);
    Livewire::test(ListGoals::class)->assertTableActionVisible('overrideAchievement', $goal)
        ->filterTable('achievement_overridden', true)->assertCanSeeTableRecords([$goal])
        ->callTableAction('overrideAchievement', $goal, data: ['actual' => null, 'reason' => 'Back to the system'])->assertHasNoTableActionErrors();
    expect($goal->refresh()->achievement_override)->toBeNull()->and($goal->finalAchievement())->toBe(70.0)
        ->and(fn () => $this->goals->overrideAchievement($goal, null, 'Again', $this->hr))->toThrow(RuntimeException::class, 'already');

    // Dataset: system and final achievement side by side.
    $this->goals->overrideAchievement($goal, 65, 'Board view', $this->hr);
    $row = collect(app(ReportRunner::class)->execute('goals', ['fields' => ['title', 'system_achievement', 'final_achievement', 'achievement_overridden']], $this->hr)->rows)->firstWhere('title', 'Revenue');
    expect($row)->toMatchArray(['system_achievement' => 70.0, 'final_achievement' => 65.0, 'achievement_overridden' => true]);

    // Switched off again: the final achievement is the system's, whatever was entered.
    $this->settings->set('performance.goals.achievement_override', false, 'Back to default');
    expect($goal->refresh()->finalAchievement())->toBe(70.0)->and(app(KpiRatings::class)->achievementPct($goal, 'achievement_over_target'))->toBe(70.0);
});
