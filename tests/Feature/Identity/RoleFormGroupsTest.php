<?php

use App\Domain\Experience\Services\MySpace;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\PermissionAreas;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 | Owner 2026-11-19: a role's permissions and its "Pages in My space" read by area and module, each explained in a few
 | lines, with what hiding from My space does and does not do. Same field, same saving: only the layout changed.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
});

it('files every permission in the catalogue under an area and a named module', function () {
    $keys = collect(config('peopleos.permissions'))->flatMap(fn (array $group) => array_keys($group));
    $modules = $keys->map(fn (string $key) => PermissionAreas::moduleOf($key))->unique();
    $inAreas = collect(PermissionAreas::AREAS)->flatMap(fn (array $area) => $area['modules']);

    expect($modules->diff($inAreas)->values()->all())->toBe([])
        ->and($modules->diff(array_keys(PermissionAreas::MODULES))->values()->all())->toBe([])
        ->and($inAreas->duplicates()->values()->all())->toBe([]);

    $groups = PermissionAreas::groups($keys->values()->all());
    expect(collect($groups)->pluck('key'))->not->toContain('other')
        ->and(collect($groups)->sum(fn (array $area) => collect($area['sections'])->sum(fn (array $s) => count($s['options']))))->toBe($keys->count());
});

it('keeps a module\'s permissions in catalogue order and names each action plainly', function () {
    $ids = Permission::query()->where('key', 'like', 'leave.%')->pluck('key', 'id')->all();
    $leave = collect(PermissionAreas::groups($ids))->firstWhere('key', 'time')['sections'][0];

    expect($leave['label'])->toBe('Leave')
        ->and(array_map(fn ($id) => $ids[$id], $leave['options']))->toBe(array_keys(config('peopleos.permissions.leave')))
        ->and(PermissionAreas::actionLabel('leave.carry_forward'))->toBe('Carry forward')
        ->and(PermissionAreas::actionLabel('compliance.returns.file'))->toBe('Returns file');
});

it('puts a permission no area names under Other permissions instead of hiding it', function () {
    $groups = PermissionAreas::groups([1 => 'leave.apply', 2 => 'zeppelin.fly']);

    expect(collect($groups)->pluck('key')->all())->toBe(['time', 'other'])
        ->and($groups[1]['sections'][0]['options'])->toBe([2]);
});

it('groups My space pages by their group, each with a plain line', function () {
    $grouped = app(MySpace::class)->grouped();

    expect(collect($grouped['groups'])->pluck('key')->all())->toBe(array_keys(MySpace::GROUPS))
        ->and(collect($grouped['groups'])->every(fn (array $g) => $g['hint'] !== ''))->toBeTrue()
        ->and($grouped['options']['payslips'])->toBe('My payslips')
        ->and(collect($grouped['groups'])->sum(fn (array $g) => count($g['sections'][0]['options'])))->toBe(count(app(MySpace::class)->all()));
});

it('shows the role steps by area and module with what each means', function () {
    $this->actingAs(tenantUser($this->tenant, ['*']));

    $this->get(RoleResource::getUrl('create'))->assertOk()
        ->assertSee('How permissions work')
        ->assertSee('Payroll &amp; statutory compliance', false)
        ->assertSee(PermissionAreas::MODULES['leave'][1])
        ->assertSee('leave.carry_forward')
        ->assertSee('What “Hide from My space” means')
        ->assertSee(MySpace::GROUP_HINTS['pay'])
        ->assertSee('data-pos-review-group="Leave"', false);
});

it('creates a role with grouped permissions and hidden My space pages exactly as before', function () {
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $ids = Permission::query()->whereIn('key', ['leave.apply', 'payroll.payslip'])->pluck('id')->map(fn ($id) => (int) $id)->all();

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'Interns', 'slug' => 'interns', 'permissions' => $ids, 'myspace_hidden_items' => ['loans']])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('slug', 'interns')->firstOrFail();
    expect($role->permissions()->pluck('key')->sort()->values()->all())->toBe(['leave.apply', 'payroll.payslip'])
        ->and($role->myspace_hidden_items)->toBe(['loans']);
});
