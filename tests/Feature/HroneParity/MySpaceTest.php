<?php

use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\MySpaceItem;
use App\Domain\Experience\Models\ExperiencePreference;
use App\Domain\Experience\MySpaceItems\PageItem;
use App\Domain\Experience\MySpaceItems\SelfPages;
use App\Domain\Experience\Services\CommandSearch;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\MySpace;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\MySpace as MySpacePage;
use App\Filament\Pages\MyTeam;
use App\Filament\Pages\TaskInbox;
use App\Filament\Pages\TeamRequests;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Livewire\Livewire;

/*
| HROne parity MS-1 (matrix 22 §22.1 #1–5, §22.1.1 #2–5): My space — every self page the person may open, grouped with a
| chip per group and a search, a heart per item (favourites, at most twelve, first on the page and in the avatar menu),
| each item a Command Center navigation result, and the organisation's "Items left out of My space" (never grants, never
| blocks a page). Availability is always the destination's own canAccess().
*/

beforeEach(function () {
    $this->travelTo('2026-10-05 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    Filament::getPanel('admin')->boot();
    Company::factory()->create();
    $this->employeeTemplate = config('peopleos.roles.employee.permissions');
    $this->asha = ms1Employee('Asha', $this->employeeTemplate);
    $this->actingAs($this->asha->user);
});

/** An employee with a login holding exactly these permissions. */
function ms1Employee(string $first, array $permissions, ?Employee $manager = null): Employee
{
    $user = tenantUser(app(TenantContext::class)->current(), $permissions, ['name' => $first.' Test']);

    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => '2025-01-01', 'user_id' => $user->id],
        ['company_id' => Company::query()->orderBy('id')->value('id')], $manager?->id)->load('user');
}

/** A fresh registry (the registry memoises its answers for one request). */
function ms1Space(): MySpace
{
    app()->forgetInstance(MySpace::class);
    app()->forgetInstance(ExperiencePreferences::class);
    app()->forgetInstance(CurrentEmployee::class);

    return app(MySpace::class);
}

/** @return list<string> the keys the signed-in person sees in My space */
function ms1Keys(?User $user = null): array
{
    return collect(ms1Space()->available($user ?? auth()->user()))->map(fn (array $e) => $e['item']->key())->all();
}

/** @return list<array{0: string, 1: ?string}> the avatar menu (name, url) for the signed-in person */
function ms1AvatarMenu(): array
{
    ms1Space();

    return collect(Filament::getPanel('admin')->getUserMenuItems())->map(fn ($action) => [$action->getName(), $action->getLabel(), $action->getUrl()])->values()->all();
}

// ---- the registry ------------------------------------------------------------------------------------------------

it('registers one item per self page, with unique keys, a known group and a page or resource list as destination', function () {
    $items = ms1Space()->all();

    expect(array_keys($items))->toBe(array_keys(SelfPages::ITEMS))
        ->and(count($items))->toBeGreaterThanOrEqual(50);
    foreach ($items as $key => $item) {
        expect($item)->toBeInstanceOf(MySpaceItem::class)
            ->and($item->key())->toBe($key)->toMatch('/^[a-z0-9_.]{1,64}$/')
            ->and(array_keys(MySpace::GROUPS))->toContain($item->group())
            ->and($item->label())->not->toBe('')
            ->and($item->description())->not->toBe('');
        $target = $item->target();
        expect(is_subclass_of($target, Page::class) || is_subclass_of($target, Resource::class))->toBeTrue($key);
    }
    // Setup centre options: every item, by key.
    expect(array_keys(ms1Space()->options()))->toEqualCanonicalizing(array_keys($items));
    // A mistyped destination fails at once.
    expect(fn () => new PageItem('bad', 'Bad', 'profile', 'heroicon-o-heart', Employee::class))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PageItem('Bad Key', 'Bad', 'profile', 'heroicon-o-heart', MyHr::class))->toThrow(InvalidArgumentException::class);
});

it('offers each item exactly when its destination\'s own canAccess() lets the signed-in person in', function () {
    $manager = ms1Employee('Mira', array_merge($this->employeeTemplate, config('peopleos.roles.manager.permissions')));
    ms1Employee('Ravi', $this->employeeTemplate, $manager);
    $hr = ms1Employee('Hari', ['*']);
    $bare = ms1Employee('Bare', ['task.view']);
    $noEmployee = tenantUser($this->tenant, $this->employeeTemplate);

    foreach ([$this->asha->user, $manager->user, $hr->user, $bare->user, $noEmployee] as $user) {
        $this->actingAs($user);
        $keys = ms1Keys();
        foreach (ms1Space()->all() as $key => $item) {
            $target = $item->target();
            expect(in_array($key, $keys, true))->toBe($target::canAccess(), "{$user->name}: {$key}");
            if (in_array($key, $keys, true)) {
                $url = collect(ms1Space()->available($user))->firstWhere(fn ($e) => $e['item']->key() === $key)['url'];
                expect($url)->toBe(is_subclass_of($target, Resource::class) ? $target::getUrl('index', SelfPages::ITEMS[$key]['parameters'] ?? []) : $target::getUrl(SelfPages::ITEMS[$key]['parameters'] ?? []));
            }
        }
    }

    // The employee template opens the everyday self pages.
    $this->actingAs($this->asha->user);
    expect(ms1Keys())->toContain('profile', 'my_hr', 'my_day', 'payslips', 'leave', 'leave_balances', 'goals', 'appraisals', 'recognition', 'my_requests', 'new_request');
});

it('never shows an item whose module right the employee lacks', function (string $permission, array $items) {
    expect(array_intersect($items, ms1Keys()))->toBe($items, 'the template opens them');

    $without = ms1Employee('Nopriv', array_values(array_diff($this->employeeTemplate, [$permission])));
    $this->actingAs($without->user);
    expect(array_intersect($items, ms1Keys()))->toBe([]);
})->with([
    'payslips' => ['payroll.payslip', ['payslips', 'investment_proofs']],
    'encashments' => ['leave.apply', ['leave_encashments']],
    'assets' => ['asset.own', ['my_assets']],
    'celebrations' => ['celebration.view', ['celebrations']],
    'learning' => ['learning.learn', ['learning']],
    'career' => ['career.self', ['career']],
]);

it('gives a manager only their own self pages, never the team pages they also run', function () {
    $manager = ms1Employee('Mira', array_merge($this->employeeTemplate, config('peopleos.roles.manager.permissions')));
    ms1Employee('Ravi', $this->employeeTemplate, $manager);
    $this->actingAs($manager->user);

    $urls = collect(ms1Space()->available($manager->user))->pluck('url')->map(fn (string $u) => parse_url($u, PHP_URL_PATH))->all();
    foreach ([MyTeam::class, TeamRequests::class, Approvals::class, TaskInbox::class] as $team) {
        expect($team::canAccess())->toBeTrue($team)
            ->and($urls)->not->toContain(parse_url($team::getUrl(), PHP_URL_PATH));
    }
    expect(ms1Keys())->toContain('profile', 'goals', 'leave', 'one_on_ones');
});

it('fails closed: only for the signed-in person, inside a tenant, and never an item that throws', function () {
    $other = ms1Employee('Other', $this->employeeTemplate);
    $this->actingAs($this->asha->user);
    expect(ms1Space()->available($other->user))->toBe([])
        ->and(ms1Space()->favourites($other->user))->toBe([])
        ->and(ms1Space()->isAvailable($other->user, 'payslips'))->toBeFalse();

    actAsTenant(null);
    expect(ms1Space()->available($this->asha->user))->toBe([]);
    actAsTenant($this->tenant);

    app()->bind('peopleos.myspace_item.broken', fn () => new class implements MySpaceItem
    {
        public function key(): string
        {
            return 'broken';
        }

        public function label(): string
        {
            return 'Broken';
        }

        public function description(): string
        {
            return 'Throws';
        }

        public function group(): string
        {
            return 'profile';
        }

        public function icon(): string
        {
            return 'heroicon-o-heart';
        }

        public function keywords(): array
        {
            return [];
        }

        public function available(User $user): bool
        {
            throw new RuntimeException('boom');
        }

        public function url(User $user): ?string
        {
            return '/x';
        }
    });
    app()->tag(['peopleos.myspace_item.broken'], 'peopleos.myspace_items');
    expect(ms1Space()->find('broken'))->not->toBeNull()
        ->and(ms1Keys())->not->toContain('broken')->toContain('payslips');
});

// ---- the page ----------------------------------------------------------------------------------------------------

it('lists the person\'s pages grouped, with a chip per group, a search and an empty state', function () {
    $this->get(MySpacePage::getUrl())->assertOk()->assertSee('My space')->assertSee('Payslips')->assertSee(PayslipResource::getUrl('index'), false);

    $page = Livewire::test(MySpacePage::class)->assertOk();
    $catalogue = $page->instance()->catalogue();
    expect(array_keys($catalogue['groups']))->toBe(array_values(array_intersect(array_keys(MySpace::GROUPS), array_keys($catalogue['groups']))))
        ->and(array_keys($catalogue['chips']))->toContain('profile', 'time', 'pay', 'performance', 'engage', 'requests')
        ->and($catalogue['total'])->toBe(count(ms1Keys()));
    $labels = collect($catalogue['groups']['pay']['items'])->pluck('label')->all();
    expect($labels)->toBe(collect($labels)->sortBy(fn ($l) => mb_strtolower($l))->values()->all());

    // A chip narrows to one group; an unknown chip shows everything.
    $page->call('setGroup', 'pay');
    expect(array_keys($page->instance()->catalogue()['groups']))->toBe(['pay']);
    $page->call('setGroup', 'nonsense')->assertSet('group', 'all');

    // HROne's names find the page ("1 on 1" → One-on-ones, "Loan history" → Loans and advances).
    $page->set('search', 'payslip');
    expect(collect($page->instance()->catalogue()['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('key'))->all())->toContain('payslips')->not->toContain('goals');
    $page->set('search', 'loan history');
    expect(collect($page->instance()->catalogue()['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('key'))->all())->toBe(['loans']);
    $page->set('search', 'zzzz')->assertSee('No page matches.');

    // No employee record of one's own: no My space.
    $this->actingAs(tenantUser($this->tenant, $this->employeeTemplate));
    expect(MySpacePage::canAccess())->toBeFalse();
    $this->get(MySpacePage::getUrl())->assertForbidden();
});

it('keeps up to twelve favourites per person, only of items they may open, first on the page and in the avatar menu', function () {
    $page = Livewire::test(MySpacePage::class);
    $page->call('toggleFavourite', 'payslips')->call('toggleFavourite', 'goals');
    expect(app(ExperiencePreferences::class)->for($this->asha->user)['favourite_pages'])->toBe(['goals', 'payslips'])
        ->and(collect($page->instance()->catalogue()['favourites'])->pluck('key')->all())->toBe(['goals', 'payslips']);
    $page->assertSee('Remove Payslips from favourites');

    // Unknown keys and items the person cannot open are refused.
    $page->call('toggleFavourite', 'nonsense')->call('toggleFavourite', 'alumni_portal');
    expect(ms1Space()->favouriteKeys($this->asha->user))->toBe(['goals', 'payslips']);

    // Un-heart.
    $page->call('toggleFavourite', 'goals');
    expect(ms1Space()->favouriteKeys($this->asha->user))->toBe(['payslips']);

    // The avatar menu: favourites first (after the account entry, which Filament always puts on top), then the existing
    // entries in their order, with "My space" before Preferences.
    $menu = collect(ms1AvatarMenu())->reject(fn ($i) => $i[0] === 'profile')->values();
    expect($menu[0])->toBe(['myspace-favourite-0', 'Payslips', PayslipResource::getUrl('index')])
        ->and($menu->pluck(0)->all())->toContain('my-profile', 'my-hr', 'my-space')
        ->and($menu->pluck(0)->search('my-profile'))->toBe(1)
        ->and($menu->pluck(0)->search('my-space'))->toBeLessThan($menu->pluck(0)->search('preferences'))
        ->and($menu->firstWhere(0, 'my-space')[2])->toBe(MySpacePage::getUrl());

    // At most twelve: the oldest heart drops off.
    $keys = array_slice(ms1Keys(), 0, 13);
    expect(count($keys))->toBe(13);
    foreach ($keys as $key) {
        if (! in_array($key, ms1Space()->favouriteKeys($this->asha->user), true)) {
            ms1Space()->toggleFavourite($this->asha->user, $key);
        }
    }
    expect(ms1Space()->favouriteKeys($this->asha->user))->toHaveCount(12);
    expect(collect(ms1AvatarMenu())->filter(fn ($i) => str_starts_with($i[0], 'myspace-favourite-')))->toHaveCount(12);

    // A favourite whose page the person can no longer open is not shown (and nothing is granted).
    app(ExperiencePreferences::class)->update($this->asha->user, ['favourite_pages' => ['payslips', 'goals']]);
    $this->asha->user->roles()->detach();
    $this->asha->user->flushPermissionCache();
    expect(ms1Space()->favourites($this->asha->user))->toBe([])
        ->and(PayslipResource::canAccess())->toBeFalse()
        ->and(collect(ms1AvatarMenu())->pluck(0)->filter(fn ($name) => str_starts_with($name, 'myspace-favourite-')))->toBeEmpty();
});

it('keeps favourites per person and per tenant', function () {
    ms1Space()->toggleFavourite($this->asha->user, 'payslips');
    $colleague = ms1Employee('Bina', $this->employeeTemplate);
    $this->actingAs($colleague->user);
    expect(ms1Space()->favouriteKeys($colleague->user))->toBe([])
        ->and(collect(ms1AvatarMenu())->pluck(0)->all())->not->toContain('myspace-favourite-0');

    $other = provisionTenant('Globex');
    actAsTenant($other);
    Company::factory()->create();
    $outsider = ms1Employee('Olga', $this->employeeTemplate);
    $this->actingAs($outsider->user);
    expect(ms1Space()->favouriteKeys($outsider->user))->toBe([]);
    ms1Space()->toggleFavourite($outsider->user, 'goals');
    expect(ExperiencePreference::query()->where('user_id', $outsider->user->id)->value('tenant_id'))->toBe($other->id);

    actAsTenant($this->tenant);
    $this->actingAs($this->asha->user);
    expect(ms1Space()->favouriteKeys($this->asha->user))->toBe(['payslips'])
        ->and(ExperiencePreference::query()->where('user_id', $outsider->user->id)->exists())->toBeFalse();
});

// ---- the organisation's "Items left out of My space" ---------------------------------------------------------------

it('leaves hidden items out of My space, its favourites and its search results, without blocking the page or granting anything', function () {
    ms1Space()->toggleFavourite($this->asha->user, 'payslips');
    $hr = tenantUser($this->tenant, ['*']);
    app(SetupCatalogue::class)->save('core', [SetupCatalogue::field('myspace.hidden_items') => ['payslips', 'alumni_portal']], 'Pay pages are on the intranet', $hr);
    expect(app(SettingsRepository::class)->get('myspace.hidden_items'))->toBe(['alumni_portal', 'payslips']);

    expect(ms1Keys())->not->toContain('payslips')->not->toContain('alumni_portal')->toContain('goals')
        ->and(ms1Space()->favourites($this->asha->user))->toBe([])
        ->and(ms1Space()->favouriteKeys($this->asha->user))->toBe(['payslips'])
        ->and(collect(ms1AvatarMenu())->pluck(0)->all())->not->toContain('myspace-favourite-0');
    Livewire::test(MySpacePage::class)->assertDontSee(PayslipResource::getUrl('index'), false)->assertSee('Goals');
    ms1Space();
    $titles = collect(app(CommandSearch::class)->search($this->asha->user, 'payslip'))->flatMap(fn ($g) => $g['items'])->pluck('id')->all();
    expect($titles)->not->toContain('myspace:payslips');

    // The page itself still opens; a hidden item the person cannot open stays closed.
    $this->get(PayslipResource::getUrl('index'))->assertOk();
    expect(ms1Keys())->not->toContain('alumni_portal');

    // Unknown keys are refused by the Setup centre.
    expect(fn () => app(SetupCatalogue::class)->save('core', [SetupCatalogue::field('myspace.hidden_items') => ['nonsense']], 'x', $hr))
        ->toThrow(RuntimeException::class, 'does not exist');

    // The default lists everything, as the pages themselves are.
    expect(config('peopleos.settings')['myspace.hidden_items'])->toBe([]);
});

// ---- Command Center ----------------------------------------------------------------------------------------------

it('finds My space items as navigation results, by their label and by HROne\'s names, once each', function () {
    $search = function (string $q): array {
        ms1Space();

        return collect(app(CommandSearch::class)->search(auth()->user(), $q))->firstWhere('key', 'modules')['items'] ?? [];
    };

    $rows = $search('1 on 1');
    expect(collect($rows)->pluck('id')->all())->toContain('myspace:one_on_ones')
        ->and(collect($rows)->firstWhere('id', 'myspace:one_on_ones'))->toMatchArray(['type' => 'module', 'title' => 'One-on-ones', 'subtitle' => 'My space · Performance']);
    expect(collect($search('loan history'))->pluck('id')->all())->toContain('myspace:loans');
    expect(collect($search('goal history'))->pluck('url')->all())->toContain(GoalResource::getUrl('index'));

    // A page already found as a module is not listed twice.
    $urls = collect($search('payslips'))->pluck('url');
    expect($urls->count())->toBe($urls->unique()->count())
        ->and($urls->all())->toContain(PayslipResource::getUrl('index'));

    // Nothing the person cannot open.
    $this->actingAs(ms1Employee('Nopay', array_values(array_diff($this->employeeTemplate, ['payroll.payslip'])))->user);
    expect(collect($search('payslip'))->pluck('url')->all())->not->toContain(PayslipResource::getUrl('index'));
});
