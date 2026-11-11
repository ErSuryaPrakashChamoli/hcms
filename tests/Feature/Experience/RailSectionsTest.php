<?php

use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\MobileNavigation;
use App\Filament\Pages\OrganisationDesigner;
use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\Vendors\VendorResource;
use Filament\Facades\Filament;

/*
| The rail: a section of several areas (People, Organisation, ...) is a button that opens its list of areas under it; it
| is never a link to a page, each area opens its own. The current section's areas start open, the others folded. No
| area carries its section's name ("Organisation › Organisation" read as one level): those areas have a rail name of
| their own, and keep their own name for their landing and everyday modules.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    $this->admin = tenantUser($this->tenant, ['*']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actAsTenant(null);
});

it('makes a section of several areas open its areas instead of a page', function () {
    $this->actingAs($this->admin);
    actAsTenant($this->tenant);
    $items = collect(app(ExperienceNavigation::class)->items($this->admin))->keyBy(fn ($item) => $item->getLabel());

    expect($items['People']->getUrl())->toBeNull()
        ->and($items['People']->getChildItems())->not->toBeEmpty()
        ->and($items['People']->getExtraAttributes())->toMatchArray(['data-pos-section' => 'people'])
        ->and($items['Organisation']->getUrl())->toBeNull()
        // Home lists no areas in the rail: it stays a link to its page.
        ->and($items['Home']->getUrl())->not->toBeNull()
        ->and($items['Home']->getChildItems())->toBe([]);
    actAsTenant(null);
});

it('gives every section its own icon, drawn for its name', function () {
    $icons = collect(ExperienceNavigation::SECTIONS)->pluck('icon');

    expect($icons->unique())->toHaveCount(count(ExperienceNavigation::SECTIONS));
    foreach ($icons as $icon) {
        expect($icon)->toStartWith('pos-rail-')
            ->and(svg($icon, 'fi-icon')->toHtml())->toContain('viewBox="0 0 24 24"')->toContain('class="fi-icon"');
    }
});

it('shows a section\'s own icon on the phone bar too', function () {
    $this->actingAs($this->admin);
    actAsTenant($this->tenant);
    // Phone bar destination => the rail section it is.
    $sections = ['home' => 'home', 'work' => 'work', 'people' => 'people', 'services' => 'services', 'pulse' => 'insights', 'org' => 'organisation', 'admin' => 'admin'];
    $seen = [];
    foreach (['employee', 'admin'] as $experience) {
        foreach (app(MobileNavigation::class)->items($this->admin, $experience, 'filament.admin.pages.home') as $item) {
            if (isset($sections[$item['key']])) {
                expect($item['icon'])->toBe(ExperienceNavigation::SECTIONS[$sections[$item['key']]]['icon']);
                $seen[] = $item['key'];
            }
        }
    }
    expect($seen)->toContain('home', 'work', 'admin');
    actAsTenant(null);
});

it('gives every section its own hue', function () {
    $this->actingAs($this->admin);
    actAsTenant($this->tenant);
    $nav = app(ExperienceNavigation::class);
    $items = collect($nav->items($this->admin))->keyBy(fn ($item) => $item->getLabel());

    expect(collect(ExperienceNavigation::SECTIONS)->pluck('hue')->unique())->toHaveCount(count(ExperienceNavigation::SECTIONS))
        ->and($items['People']->getExtraAttributes())->toMatchArray(['data-pos-hue' => 'blue'])
        ->and($items['Home']->getExtraAttributes())->toMatchArray(['data-pos-hue' => 'iris']);
    actAsTenant(null);
});

it('draws a section as a button and opens only the current one', function () {
    $this->actingAs($this->admin);
    $html = $this->get(LeaveRequestResource::getUrl('index'))->assertOk()->getContent();

    preg_match_all('/<button\s+type="button"\s+aria-expanded="(true|false)"\s+aria-controls="pos-rail-areas-(\w+)"\s+data-pos-rail-toggle/', $html, $m);
    $sections = array_combine($m[2], $m[1]);
    expect($sections)->toHaveKeys(['people', 'organisation', 'admin'])
        ->and($sections['people'])->toBe('true')
        ->and($sections['organisation'])->toBe('false')
        ->and(array_count_values($sections)['true'])->toBe(1)
        ->and($html)->toMatch('/id="pos-rail-areas-organisation"\s+data-pos-rail-closed/')
        ->and($html)->not->toMatch('/id="pos-rail-areas-people"\s+data-pos-rail-closed/')
        // The section's tile, its name and its toggle orb; no line of details under the name.
        ->and($html)->toContain('class="pos-rail-tile"')->toContain('class="pos-rail-orb"')
        ->and($html)->toMatch('/<span class="pos-rail-title">People\b/')
        ->and($html)->toContain('<span class="pos-rail-tile">')
        ->and($html)->not->toContain('pos-rail-caption');
});

it('names no area after its section', function () {
    $this->actingAs($this->admin);
    actAsTenant($this->tenant);
    $nav = app(ExperienceNavigation::class);
    $rail = fn (string $section) => array_column($nav->children($this->admin, $section), 'label');

    foreach ($nav->sections($this->admin) as $section) {
        expect($rail($section))->not->toContain(ExperienceNavigation::SECTIONS[$section]['label']);
    }
    expect($rail('organisation'))->toContain('Structure')
        ->and($rail('communication'))->toContain('Messaging')
        ->and($rail('workflows'))->toContain('Process flows');
    actAsTenant(null);
});

it('shows the rail name in the area bar and keeps the area\'s everyday modules', function () {
    $this->actingAs($this->admin);
    $html = $this->get(DepartmentResource::getUrl('index'))->assertOk()->assertSee('All in Structure')->getContent();

    $bar = substr($html, (int) strpos($html, 'class="pos-spacebar"'));
    $bar = substr($bar, 0, (int) strpos($bar, '</nav>'));
    preg_match_all('/<a href="([^"]+)"[^>]*class="pos-spacebar-link"/', $bar, $m);
    expect($bar)->toMatch('/Organisation\s*<span aria-hidden="true">\/<\/span>\s*Structure/')
        ->and($m[1])->toContain(OrganisationDesigner::getUrl())
        ->and($m[1])->not->toContain(VendorResource::getUrl('index'));
});
