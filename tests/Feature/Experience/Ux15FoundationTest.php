<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\PersonPeek;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Livewire\Experience\PeekHost;

/*
| UX.15.4: the governed design system and the Peek level of Peek → Drawer → Workspace.
| - The theme keeps its governance rules (tokens only; nothing below 12 px).
| - A peek shows directory fields only, for people the viewer may already find, and answers a hidden or
|   foreign record exactly like a missing one.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->delhi = Location::factory()->create(['name' => 'Delhi']);
    $this->pune = Location::factory()->create(['name' => 'Pune']);
    $company = Company::query()->first() ?? Company::factory()->create();
    $hire = fn (string $first, string $last, ?Employee $manager, Location $loc, array $perms) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => $last],
        ['joining_date' => '2024-01-01', 'user_id' => tenantUser($this->tenant, $perms)->id],
        ['company_id' => $company->id, 'location_id' => $loc->id],
        $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->boss = $hire('Bhavna', 'Boss', null, $this->delhi, ['employee.view']);
    $this->priya = $hire('Priya', 'Nair', $this->boss, $this->delhi, ['leave.apply']);
    $this->stranger = $hire('Sunil', 'Rao', null, $this->pune, ['leave.apply']);
    $this->hr = tenantUser($this->tenant, ['employee.view']);

    $this->other = provisionTenant('Elsewhere');
    actAsTenant($this->other);
    $this->foreign = app(HireEmployeeAction::class)->handle(['first_name' => 'Zed', 'last_name' => 'Outsider'], ['joining_date' => '2024-01-01'], ['company_id' => Company::factory()->create()->id]);
    actAsTenant($this->tenant);
});

it('keeps the design system governed: tokens only, and no text below 12 px', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));
    $rules = substr($css, strpos($css, '/* ------------------------------------------------------------------ 2. Base'));

    expect($rules)->not->toMatch('/font-size:\s*[0-9.]+(px|rem)/')
        ->and($rules)->not->toMatch('/border-radius:[^;]*\b(?!1px)[0-9.]+px/');
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $rules, $hex);
    expect(array_unique($hex[0]))->toBe(array_values(array_unique(array_filter($hex[0], fn ($h) => $h === '#000'))));

    preg_match_all('/--pos-fs-[a-z]+:\s*([0-9.]+)px/', $css, $sizes);
    expect(count($sizes[1]))->toBe(8)->and(min(array_map('floatval', $sizes[1])))->toBeGreaterThanOrEqual(12.0);
});

it('peeks only at people the viewer may find, with directory fields only', function () {
    $peek = app(PersonPeek::class);

    // Without employee.view: the viewer's own circle; no lifecycle status or profile link they may not open.
    $boss = $peek->for($this->priya->user, $this->boss->id);
    expect($boss)->not->toBeNull()
        ->and($boss['name'])->toBe('Bhavna Boss')
        ->and($boss['location'])->toBe('Delhi')
        ->and(array_keys($boss))->toBe(['id', 'name', 'initials', 'tone', 'title', 'team', 'location', 'manager', 'status', 'profile'])
        ->and($boss['status'])->toBeNull()
        ->and($boss['profile'])->toBeNull()
        ->and($peek->for($this->priya->user, $this->stranger->id))->toBeNull();

    // With employee.view: anyone in scope, with the status and profile the existing policy allows.
    $stranger = $peek->for($this->hr, $this->stranger->id);
    expect($stranger['status'])->toBe('Active')->and($stranger['profile'])->toContain('/employees/');

    // Another tenant's employee answers exactly like a missing id.
    expect($peek->for($this->hr, $this->foreign->id))->toBeNull()
        ->and($peek->for($this->hr, 999999))->toBeNull();
});

it('applies organisation scope to the peek', function () {
    $delhiHr = tenantUser($this->tenant, ['employee.view']);
    app(AccessScopes::class)->assign($delhiHr, ['location' => [$this->delhi->id]], 'Delhi only');

    expect(app(PersonPeek::class)->for($delhiHr, $this->priya->id))->not->toBeNull()
        ->and(app(PersonPeek::class)->for($delhiHr, $this->stranger->id))->toBeNull();
});

it('serves peeks through the peek host for the signed-in user only', function () {
    $this->actingAs($this->priya->user);
    $host = new PeekHost;

    expect($host->peek($this->boss->id)['name'])->toBe('Bhavna Boss')
        ->and($host->peek($this->stranger->id))->toBeNull()
        ->and($host->peek($this->foreign->id))->toBeNull();

    // Every person chip on a page carries data-person, which is what the peek and the drawer use.
    $html = view('components.pos.person', ['id' => $this->boss->id, 'name' => 'Bhavna Boss'])->render();
    expect($html)->toContain('data-person="'.$this->boss->id.'"')->toContain("type: 'person'");
});
