<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\PlanState;
use App\Domain\Entitlements\Models\Plan;
use App\Domain\Entitlements\Models\PlanEntitlement;
use App\Domain\Entitlements\Services\PlanCatalog;
use App\Filament\Pages\PlatformPlansPage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/PlanTestHelpers.php';

/*
| SaaS.4: the commercial plan catalogue. Plans, immutable versions (draft → published → retired), what a version
| includes against the code-owned capability catalogue, protected capabilities, operator-only reasoned changes on
| the platform audit chain. No tenant is involved here.
*/

beforeEach(function () {
    $this->travelTo('2027-01-04 09:00:00');
    $this->operator = platformAdmin();
    $this->catalog = app(PlanCatalog::class);
});

it('creates a plan with an empty draft v1, audited on the platform chain; the code is validated, unique and permanent', function () {
    $plan = $this->catalog->create('growth', 'Growth', 'Mid-size companies', 'New commercial offer', $this->operator);

    expect($plan->versions)->toHaveCount(1)
        ->and($plan->versions->first()->status)->toBe(VersionStatus::Draft)
        ->and($plan->versions->first()->version)->toBe(1)
        ->and($plan->state())->toBe(PlanState::Draft);
    $event = AuditEvent::query()->withoutTenancy()->where('action', 'PLAN_CREATED')->sole();
    expect($event->tenant_id)->toBeNull()
        ->and($event->reason)->toBe('New commercial offer')
        ->and($event->actor_id)->toBe($this->operator->id)
        ->and($event->entity_type)->toBe(Plan::class)
        ->and($event->metadata['plan'])->toBe('growth');

    expect(fn () => $this->catalog->create('growth', 'Growth again', null, 'Duplicate attempt', $this->operator))->toThrow(RuntimeException::class, 'already exists')
        ->and(fn () => $this->catalog->create('Growth Plan!', 'Bad', null, 'Bad code here', $this->operator))->toThrow(RuntimeException::class, 'plan code')
        ->and(fn () => $plan->forceFill(['code' => 'renamed'])->save())->toThrow(RuntimeException::class, 'never changes')
        ->and(fn () => $plan->delete())->toThrow(RuntimeException::class, 'never deleted');

    $this->catalog->update($plan, 'Growth (2027)', null, 'Rename for 2027', $this->operator);
    $this->catalog->update($plan, 'Growth (2027)', null, 'Same name again', $this->operator); // no change, no audit
    expect($plan->fresh()->name)->toBe('Growth (2027)')
        ->and($plan->fresh()->code)->toBe('growth')
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'PLAN_UPDATED')->sole()->fieldChanges->map(fn ($c) => [$c->field, $c->before, $c->after])->all())
        ->toBe([['name', 'Growth', 'Growth (2027)'], ['description', 'Mid-size companies', null]]);
});

it('moves versions through draft, scheduled, active, superseded and retired, and never changes a published one', function () {
    $plan = $this->catalog->create('growth', 'Growth', null, 'New commercial offer', $this->operator);
    $v1 = $plan->versions->first();
    expect(fn () => $this->catalog->publish($v1, '2027-01-11', 'Nothing in it yet', $this->operator))->toThrow(RuntimeException::class, 'at least one capability');

    $this->catalog->define($v1, ['payroll' => true, 'leave' => true, 'active_employees.max' => 100], 'Initial content', $this->operator);
    $this->catalog->publish($v1, '2027-01-11', 'Launch next week', $this->operator, 'First version');
    $v1->refresh();
    expect($v1->status)->toBe(VersionStatus::Published)
        ->and($v1->state('2027-01-04'))->toBe(PlanState::Scheduled)
        ->and($v1->state('2027-01-11'))->toBe(PlanState::Active)
        ->and($plan->fresh()->state())->toBe(PlanState::Scheduled)
        ->and($plan->fresh()->state('2027-01-11'))->toBe(PlanState::Active);

    // One draft at a time; a new draft copies the latest version.
    $v2 = $this->catalog->draft($plan, 'Raise the limit', $this->operator);
    expect($v2->version)->toBe(2)
        ->and($this->catalog->draft($plan, 'Second draft attempt', $this->operator)->id)->toBe($v2->id)
        ->and($this->catalog->values($v2))->toEqual(['payroll' => true, 'leave' => true, 'active_employees.max' => 100]);
    $this->catalog->set($v2, Capability::ActiveEmployeesMax, 250, 'Raise to 250', $this->operator);

    // Publishing v2 ends v1's sale the day before; v1 itself is unchanged and stays readable.
    $this->travelTo('2027-02-01 09:00:00');
    $this->catalog->publish($v2, '2027-03-01', 'Second price book', $this->operator);
    $v1->refresh();
    expect($v1->effective_to->toDateString())->toBe('2027-02-28')
        ->and($v1->state('2027-02-15'))->toBe(PlanState::Active)
        ->and($v1->state('2027-03-01'))->toBe(PlanState::Superseded)
        ->and($v2->fresh()->state('2027-03-01'))->toBe(PlanState::Active)
        ->and($this->catalog->values($v1))->toEqual(['payroll' => true, 'leave' => true, 'active_employees.max' => 100]);

    $this->catalog->retire($v1, 'Old price book withdrawn', $this->operator);
    $this->catalog->retire($v1->fresh(), 'Retire again', $this->operator); // idempotent
    expect($v1->fresh()->state('2027-02-15'))->toBe(PlanState::Retired)
        ->and($plan->fresh()->state('2027-03-01'))->toBe(PlanState::Active)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'PLAN_VERSION_RETIRED')->count())->toBe(1);

    // Moves that are not allowed.
    $v3 = $this->catalog->draft($plan, 'Third version', $this->operator);
    expect(fn () => $this->catalog->publish($v2->fresh(), '2027-04-01', 'Publish twice', $this->operator))->toThrow(RuntimeException::class, 'already published')
        ->and(fn () => $this->catalog->retire($v3, 'Retire a draft', $this->operator))->toThrow(RuntimeException::class, 'nothing to retire')
        ->and(fn () => $this->catalog->publish($v3, '2027-01-31', 'In the past', $this->operator))->toThrow(RuntimeException::class, 'today or later')
        ->and(fn () => $this->catalog->publish($v3, '2027-02-15', 'Before v2 starts', $this->operator))->toThrow(RuntimeException::class, 'must start after')
        ->and(fn () => $this->catalog->publish($v3, '2027-02-30', 'Not a date', $this->operator))->toThrow(RuntimeException::class, 'not a date');
    expect($v3->fresh()->status)->toBe(VersionStatus::Draft);
});

it('never changes a published version: not through the catalogue, not through the model', function () {
    $v1 = publishedPlan($this->operator, 'starter', ['leave' => true, 'attendance' => true], '2027-01-04');
    $row = $v1->entitlements()->first();

    expect(fn () => $this->catalog->define($v1, ['leave' => true], 'Edit after publish', $this->operator))->toThrow(RuntimeException::class, 'never change')
        ->and(fn () => $row->update(['value_bool' => false]))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $row->delete())->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => PlanEntitlement::query()->create(['plan_version_id' => $v1->id, 'capability' => 'ai', 'value_bool' => true]))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $v1->update(['effective_from' => '2027-02-01']))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $v1->fresh()->update(['status' => VersionStatus::Draft]))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $v1->fresh()->delete())->toThrow(RuntimeException::class, 'never deleted');
    expect($this->catalog->values($v1->fresh()))->toEqual(['leave' => true, 'attendance' => true])
        ->and($v1->fresh()->effective_from->toDateString())->toBe('2027-01-04');
});

it('accepts only catalogue capabilities with the right kind of value, and never switches a protected one off', function () {
    $draft = $this->catalog->create('custom', 'Custom', null, 'Enterprise deal', $this->operator)->versions->first();
    $refused = [
        [['no.such' => true], 'not a capability'],
        [['core' => true], 'not a commercial capability'],
        [['leave' => 5], 'included or not'],
        [['users.max' => true], 'whole number'],
        [['users.max' => -1], 'whole number'],
        [['payroll' => false], 'protected'],
        [['onboarding' => false], 'protected'],
        [['exit' => false], 'protected'],
        [['active_employees.max' => 0], 'protected'],
    ];
    foreach ($refused as [$values, $message]) {
        expect(fn () => $this->catalog->define($draft, $values, 'Invalid content', $this->operator))->toThrow(RuntimeException::class, $message);
    }
    expect(PlanEntitlement::query()->count())->toBe(0);

    // Protected capabilities can be included or left out; the others can also be excluded explicitly.
    $this->catalog->define($draft, ['payroll' => true, 'ai' => false, 'active_employees.max' => 1, 'users.max' => null], 'Valid content', $this->operator);
    $this->catalog->set($draft, Capability::Ai, false, 'Same value again', $this->operator);
    $this->catalog->set($draft, Capability::Leave, true, 'Add leave', $this->operator);
    $this->catalog->set($draft, Capability::Leave, true, 'Add leave again', $this->operator);
    $this->catalog->remove($draft, Capability::Payroll, 'Payroll sold separately', $this->operator);
    expect($this->catalog->values($draft))->toEqual(['ai' => false, 'active_employees.max' => 1, 'users.max' => null, 'leave' => true])
        ->and(PlanEntitlement::query()->where('capability', 'leave')->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'PLAN_VERSION_EDITED')->count())->toBe(3);

    // The database refuses a second row for one capability in one version.
    expect(fn () => DB::table('plan_entitlements')->insert(['plan_version_id' => $draft->id, 'capability' => 'leave', 'value_bool' => true, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
    $removal = AuditEvent::query()->withoutTenancy()->where('action', 'PLAN_VERSION_EDITED')->orderByDesc('id')->first();
    expect($removal->fieldChanges->map(fn ($c) => [$c->field, $c->before, $c->after])->all())->toBe([['payroll', 'included', 'not in plan']])
        ->and($removal->reason)->toBe('Payroll sold separately');
});

it('lets only platform operators change the catalogue, always with a reason', function () {
    $tenant = provisionTenant('Alpha');
    actAsTenant($tenant);
    $admin = tenantUser($tenant, ['*']);
    $flagged = tenantUser($tenant, ['*']);
    $flagged->forceFill(['is_platform_admin' => true])->save(); // a tenant user carrying the flag is still not an operator

    foreach ([$admin, $flagged->fresh()] as $user) {
        expect(fn () => $this->catalog->create('mine', 'Mine', null, 'Self-service plan', $user))->toThrow(RuntimeException::class, 'Only platform operators');
    }
    expect(fn () => $this->catalog->create('growth', 'Growth', null, 'no', $this->operator))->toThrow(RuntimeException::class, 'reason')
        ->and(Plan::query()->count())->toBe(0);

    $plan = $this->catalog->create('growth', 'Growth', null, 'New commercial offer', $this->operator);
    expect(fn () => $this->catalog->define($plan->versions->first(), ['leave' => true], 'Tenant edit', $admin))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(fn () => $this->catalog->retire($plan->versions->first(), 'Tenant retire', $admin))->toThrow(RuntimeException::class, 'Only platform operators');

    $this->actingAs($admin)->get(PlatformPlansPage::getUrl())->assertForbidden();
    expect(PlatformPlansPage::canAccess())->toBeFalse();
});

it('lets an operator run a plan end to end on Platform › Plans', function () {
    actAsTenant(null);
    $this->actingAs($this->operator);
    $this->get(PlatformPlansPage::getUrl())->assertOk()->assertSee('No plans yet');

    $page = Livewire::test(PlatformPlansPage::class)
        ->callAction('createPlan', data: ['code' => 'growth', 'name' => 'Growth', 'reason' => 'New commercial offer'])
        ->assertHasNoActionErrors();
    $plan = Plan::query()->sole();
    $page->assertSet('plan', $plan->id)
        ->callAction('editDraft', data: ['cap_payroll' => 'included', 'cap_ai' => 'included', 'cap_ai__external_model' => 'included',
            'cap_active_employees__max' => 'limited', 'cap_active_employees__max_value' => 200, 'cap_users__max' => 'unlimited', 'reason' => 'Initial content'])
        ->assertHasNoActionErrors()
        ->callAction('publish', data: ['from' => '2027-01-04', 'change_note' => 'Launch', 'reason' => 'Launch the plan'])
        ->assertHasNoActionErrors();
    $v1 = $plan->versions()->sole();
    expect($this->catalog->values($v1))->toEqual(['payroll' => true, 'ai' => true, 'ai.external_model' => true, 'active_employees.max' => 200, 'users.max' => null])
        ->and($v1->status)->toBe(VersionStatus::Published);

    $this->get(PlatformPlansPage::getUrl(['plan' => $plan->id]))->assertOk()
        ->assertSee('growth')->assertSee('PLAN_VERSION_PUBLISHED')->assertSee('Launch the plan')->assertSee('not in plan')->assertSee('200');

    Livewire::test(PlatformPlansPage::class, ['plan' => $plan->id])
        ->callAction('newDraft', data: ['reason' => 'Next version'])
        ->callAction('retire', data: ['version' => $v1->id, 'reason' => 'Withdrawn from sale'])
        ->assertHasNoActionErrors();
    expect($plan->versions()->pluck('status')->map->value->all())->toBe(['retired', 'draft']);
});
