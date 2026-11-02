<?php

use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Establishments\EstablishmentResource;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Livewire\Experience\AreaBar;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| The area bar leads with the page you are on, then the person's favourites, then the area's everyday modules;
| "All in {area}" lists the favourites in a row of their own above every module of the area. On a module's own
| page the bar names the page: the header drops its title and the page's buttons sit in the table's toolbar.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    $this->admin = tenantUser($this->tenant, ['*']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actAsTenant(null);
});

/** The links of the bar's row, in order (the "All in" panel comes after the nav). */
function areaRow(string $html): array
{
    $nav = substr($html, (int) strpos($html, 'class="pos-spacebar"'));
    $nav = substr($nav, 0, (int) strpos($nav, '</nav>'));
    preg_match_all('/<a href="([^"]+)"[^>]*class="pos-spacebar-link"/', $nav, $m);

    return $m[1];
}

it('puts the page you are on first in the row and names it in place of the page title', function () {
    $this->actingAs($this->admin);

    $html = $this->get(EstablishmentResource::getUrl('index'))->assertOk()->getContent();

    expect(areaRow($html)[0])->toBe(EstablishmentResource::getUrl('index'))
        ->and($html)->toContain('data-pos-landing')
        ->toContain('<h1 class="sr-only">'.EstablishmentResource::getNavigationLabel().'</h1>')
        // The page's "New establishment" button sits in the table's toolbar, between the search and the filters.
        ->toContain('pos-toolbar-actions');
    expect(strpos($html, 'fi-ta-search-field'))->toBeLessThan(strpos($html, 'pos-toolbar-actions'));

    // Another module of the area moves to the front when it is opened.
    expect(areaRow($this->get(CompanyResource::getUrl('index'))->assertOk()->getContent())[0])->toBe(CompanyResource::getUrl('index'));
});

it('keeps the title and buttons in the header of a record page', function () {
    $this->actingAs($this->admin);

    $create = $this->get(CompanyResource::getUrl('create'))->assertOk()->getContent();

    expect($create)->toContain('class="pos-spacebar"')->toContain('aria-current="page"')
        ->not->toContain('data-pos-landing')->not->toContain('pos-toolbar-actions');
});

it('leads with favourites after the current page and lists them apart under "All in"', function () {
    $this->actingAs($this->admin);
    actAsTenant($this->tenant);
    $nav = app(ExperienceNavigation::class);
    expect($nav->toggleFavouriteModule($this->admin, CompanyResource::class))->toBeTrue()
        ->and($nav->toggleFavouriteModule($this->admin, 'App\\Not\\AModule'))->toBeFalse()
        ->and(app(ExperiencePreferences::class)->for($this->admin)['favourite_modules'])->toBe([CompanyResource::class]);
    actAsTenant(null);

    $html = $this->get(EstablishmentResource::getUrl('index'))->assertOk()->getContent();
    $row = areaRow($html);

    expect(array_slice($row, 0, 2))->toBe([EstablishmentResource::getUrl('index'), CompanyResource::getUrl('index')])
        ->and(count($row))->toBe(count(array_unique($row)))
        ->and($html)->toContain('data-pos-fav')->toContain('class="pos-spacebar-favs"')
        ->toContain('Remove '.CompanyResource::getNavigationLabel().' from favourites');
    // The favourites row comes before the groups, and the favourite is still listed in its group.
    $menu = substr($html, (int) strpos($html, 'id="pos-area-all"'));
    expect(strpos($menu, 'pos-spacebar-favs'))->toBeLessThan(strpos($menu, 'pos-spacebar-groups'))
        ->and(substr_count($menu, 'href="'.CompanyResource::getUrl('index').'"'))->toBe(2);
});

it('stars and unstars from the bar, and never stores a module the person cannot open', function () {
    $clerk = tenantUser($this->tenant, ['organisation.view']);
    $this->actingAs($clerk);
    actAsTenant($this->tenant);

    Livewire::test(AreaBar::class)->call('toggleFavourite', PayrollRunResource::class);
    expect(app(ExperiencePreferences::class)->for($clerk)['favourite_modules'])->toBe([]);

    $this->actingAs($this->admin);
    Livewire::test(AreaBar::class)->call('toggleFavourite', CompanyResource::class);
    expect(app(ExperiencePreferences::class)->for($this->admin)['favourite_modules'])->toBe([CompanyResource::class]);
    Livewire::test(AreaBar::class)->call('toggleFavourite', CompanyResource::class);
    expect(app(ExperiencePreferences::class)->for($this->admin)['favourite_modules'])->toBe([]);
});
