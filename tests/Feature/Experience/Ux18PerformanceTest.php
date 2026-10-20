<?php

use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ChangeFeed;
use App\Domain\Experience\Services\PeopleVisibility;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\AuthorizationContext;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Organisation\Models\Company;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\FeatureFlags;
use App\Filament\Pages\MyTeam;
use App\Livewire\Experience\CommandCenter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| UX.18 performance fixes keep every answer identical and every boundary in place: the directory's scope applied once
| still returns exactly the people the scope allows (and stays doubled when asked for someone else), the change feed's
| authorisation pass returns the same items and no longer grows with the number of people, relationship checks agree
| inside and outside a pass, the per-request "who am I" and feature-flag answers follow writes, the aggregated
| headcount equals the single-day counts, and the command center only searches once it is opened.
*/

function ux18User(Tenant $tenant, array $roles, ?Employee $employee = null): User
{
    return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $roles, $employee) {
        $user = User::factory()->forTenant($tenant)->create();
        $user->roles()->attach(Role::query()->whereIn('slug', $roles)->pluck('id'));
        if ($employee !== null) {
            LifecycleEngine::unguarded(fn () => $employee->forceFill(['user_id' => $user->id])->save());
        }

        return $user;
    });
}

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = Company::factory()->create(['name' => 'Acme Tech']);
    $this->otherCompany = Company::factory()->create(['name' => 'Acme Services']);
    $this->hire = fn (string $first, ?Employee $manager = null, ?Company $company = null) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Perf'], ['joining_date' => '2026-09-30', 'work_email' => strtolower($first).'.perf@acme.test'],
        ['company_id' => ($company ?? $this->company)->id], $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->manager = ($this->hire)('Maya');
    $this->report = ($this->hire)('Ravi', $this->manager);
    $this->stranger = ($this->hire)('Omar', null, $this->otherCompany);
    $this->hr = ux18User($this->tenant, ['hr-manager']);
    app(AccessScopes::class)->assign($this->hr, ['company' => [(int) $this->company->id]], 'UX.18 test: one company');
});

it('applies the directory scope once for the signed-in person and returns exactly the people the scope allows', function () {
    $this->actingAs($this->hr);
    $query = app(PeopleVisibility::class)->query($this->hr);

    $scoped = Employee::query()->pluck('id')->sort()->values()->all(); // the global scope alone, for the signed-in HR
    expect($query->pluck('employees.id')->sort()->values()->all())->toBe($scoped)
        ->and($scoped)->toContain($this->manager->id, $this->report->id)->not->toContain($this->stranger->id)
        ->and(preg_match_all('/from [`"]employee_positions[`"]/i', $query->toRawSql()))->toBe(1);

    // Asked about someone other than the signed-in person, both constraints stay (fail-closed, as before).
    $this->actingAs(ux18User($this->tenant, ['hr-manager']));
    app(AccessScopes::class)->assign(auth()->user(), ['company' => [(int) $this->otherCompany->id]], 'UX.18 test');
    $forHr = app(PeopleVisibility::class)->query($this->hr);
    expect(preg_match_all('/from [`"]employee_positions[`"]/i', $forHr->toRawSql()))->toBe(2)
        ->and($forHr->pluck('employees.id')->all())->not->toContain($this->stranger->id);
});

it('keeps the change feed identical and no longer adds queries per person', function () {
    $this->actingAs($this->hr);
    $first = app(ChangeFeed::class)->for($this->hr, 120, 30);
    expect($first->pluck('subject_id')->filter()->all())->not->toContain($this->stranger->id);

    $count = function () {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        app(CurrentEmployee::class)->forget();
        app(ChangeFeed::class)->for($this->hr, 120, 30);

        return $n;
    };
    $few = $count();
    foreach (range(1, 15) as $i) {
        ($this->hire)("Joiner{$i}");
    }
    $more = $count();
    $items = app(ChangeFeed::class)->for($this->hr, 120, 30);

    expect($items->count())->toBeGreaterThan($first->count())
        ->and($more)->toBeLessThanOrEqual($few + 2) // constant: the per-person visibility and relationship checks are batched
        ->and($items->pluck('subject_id')->filter()->all())->not->toContain($this->stranger->id);
});

it('answers "does this manager manage this person" the same inside and outside an authorisation pass', function () {
    $relationships = app(PerformanceRelationships::class);
    $outside = [$relationships->manages($this->manager, $this->report->id), $relationships->manages($this->manager, $this->stranger->id), $relationships->manages(null, $this->report->id)];
    $inside = app(AuthorizationContext::class)->run(fn () => [$relationships->manages($this->manager, $this->report->id), $relationships->manages($this->manager, $this->stranger->id), $relationships->manages(null, $this->report->id)]);

    expect($outside)->toBe([true, false, false])->and($inside)->toBe($outside);
});

it('remembers who the signed-in person is for the request, and forgets it when an employee record is saved', function () {
    $user = ux18User($this->tenant, ['employee']);
    $this->actingAs($user);
    expect(app(CurrentEmployee::class)->of($user))->toBeNull();

    LifecycleEngine::unguarded(fn () => $this->report->forceFill(['user_id' => $user->id])->save());
    expect(app(CurrentEmployee::class)->of($user)?->id)->toBe($this->report->id);

    // Another tenant's context never sees this answer.
    $other = provisionTenant('Elsewhere Perf');
    actAsTenant($other);
    expect(app(CurrentEmployee::class)->of($user))->toBeNull();
});

it('reads feature flags once per request and sees a change made in the same request', function () {
    $flags = app(FeatureFlags::class);
    $before = $flags->enabled('ai.assistants');
    $flags->set('ai.assistants', ! $before, 'UX.18 test');

    expect(app(FeatureFlags::class)->enabled('ai.assistants'))->toBe(! $before);
});

it('counts the headcount on many days in one query, equal to counting each day', function () {
    forceLifecycle($this->stranger, 'exited', ['exit_date' => '2026-08-31']);
    $metrics = app(WorkforceMetrics::class);
    $days = ['2026-01-31', '2026-08-15', '2026-09-30', '2026-10-05'];
    $single = array_combine($days, array_map(fn ($d) => $metrics->headcount($d), $days));

    $n = 0;
    DB::listen(function () use (&$n) {
        $n++;
    });
    $batched = $metrics->headcounts($days);

    expect($batched)->toBe($single)->and($n)->toBe(1)
        ->and($metrics->series('headcount', 3)['series']['Headcount'])->toBe([(float) $metrics->headcount('2026-08-31'), (float) $metrics->headcount('2026-09-30'), (float) $metrics->headcount('2026-10-31')]);
});

it('offers My team from the manager lens, exactly as before', function () {
    $managerUser = ux18User($this->tenant, ['employee', 'manager'], $this->manager);
    $this->actingAs($managerUser);
    expect(MyTeam::canAccess())->toBeTrue();

    $this->actingAs(ux18User($this->tenant, ['employee'], $this->stranger));
    expect(MyTeam::canAccess())->toBeFalse();
});

it('searches only once the command center is opened', function () {
    $this->actingAs(ux18User($this->tenant, ['employee'], $this->report));
    $center = Livewire::test(CommandCenter::class);
    expect($center->get('active'))->toBeFalse()->and($center->html())->not->toContain('role="option"');

    $center->call('opened', 'all');
    expect($center->get('active'))->toBeTrue()->and($center->html())->toContain('role="option"');
});
