<?php

use App\Domain\Ai\Services\ConfigurationSearch;
use App\Filament\Pages\AdminCentre;
use App\Filament\Pages\ConfigurationFinder;
use App\Filament\Pages\WorkforceDesk;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Areas down the left, the open area's screens beside them (x-pos.browse) instead of a grid of cards: the Admin Centre,
 * the configuration finder and the operations desks render every area heading with one panel each, the first open and
 * the others cloaked until the browser opens them. Nothing is dropped: every screen the viewer may open is still linked.
 */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    Filament::getPanel('admin')->boot();
});

/** @return array{labels: list<string>, expanded: list<string>, panels: int, cloaked: int} */
function browseLayoutOf(string $html): array
{
    preg_match_all('/<button[^>]*class="pos-browse-head"[^>]*aria-expanded="(true|false)"[^>]*>.*?<span class="pos-browse-label">(.*?)<\/span>/s', $html, $m);
    preg_match_all('/<section[^>]*class="pos-panel pos-browse-panel"[^>]*>/', $html, $panels);

    return [
        'labels' => array_map(fn (string $l) => html_entity_decode($l), $m[2]),
        'expanded' => $m[1],
        'panels' => count($panels[0]),
        'cloaked' => count(array_filter($panels[0], fn (string $tag) => str_contains($tag, 'x-cloak'))),
    ];
}

it('lists the Admin Centre areas down the left, each with every module in its own panel', function () {
    $categories = Livewire::test(AdminCentre::class)->instance()->categories;
    $html = $this->get(AdminCentre::getUrl())->assertOk()->getContent();
    $browse = browseLayoutOf($html);

    expect($categories)->not->toBeEmpty()
        ->and($browse['labels'])->toBe(array_column($categories, 'label'))
        ->and($browse['expanded'])->toBe(['true', ...array_fill(0, count($categories) - 1, 'false')])
        ->and($browse['panels'])->toBe(count($categories))
        ->and($browse['cloaked'])->toBe(count($categories) - 1)
        ->and($html)->not->toContain('pos-manage-grid');
    foreach ($categories as $category) {
        foreach ($category['modules'] as $module) {
            expect($html)->toContain('href="'.e($module['url']).'"');
        }
    }
});

it('lists a module\'s screens, then its settings by what they belong to under their own names', function () {
    $page = Livewire::test(ConfigurationFinder::class);
    $growth = collect($page->instance()->browseGroups())->firstWhere('key', 'growth');
    $sets = collect($growth['sets'])->mapWithKeys(fn (array $set) => [$set['label'] => array_column($set['items'], 'label')]);

    // Every growth setting starts with "performance", so the module's own name is not repeated on each list.
    expect(array_column($growth['items'], 'label'))->toContain('Performance cycles')
        ->and($sets['Labels'])->toContain('KRA', 'KPI', 'UOM')
        ->and($sets->keys()->first())->toBe('General')
        ->and($sets->keys()->implode(' '))->not->toContain('Performance')
        ->and($page->html())->not->toContain('>Setting<');
    expect(ConfigurationSearch::settingParts('payroll.tax.form12bb_visibility'))->toBe(['group' => 'Payroll · Tax', 'label' => 'Form 12BB visibility'])
        ->and(ConfigurationSearch::settingParts('payroll.tax.max_landlords', 'payroll'))->toBe(['group' => 'Tax', 'label' => 'Maximum landlords'])
        ->and(ConfigurationSearch::settingParts('leave.year_start_month', 'leave'))->toBe(['group' => 'General', 'label' => 'Year start month']);
});

it('puts every match first while searching the configuration finder, then the matches module by module', function () {
    $page = Livewire::test(ConfigurationFinder::class);
    expect(array_column($page->instance()->browseGroups(), 'key'))->not->toContain('matches');

    $page->set('term', 'leave');
    $groups = $page->instance()->browseGroups();
    $modules = $page->instance()->modules;
    $listed = fn (array $group) => count($group['items']) + array_sum(array_map(fn (array $set) => count($set['items']), $group['sets']));

    expect($groups[0]['key'])->toBe('matches')
        ->and($listed($groups[0]))->toBe(array_sum(array_map(fn (array $m) => count($m['items']), $modules)))
        ->and(array_column(array_slice($groups, 1), 'key'))->toBe(array_column($modules, 'key'))
        ->and(browseLayoutOf($page->html())['labels'][0])->toBe('All matches');
});

it('shows an operations desk as its sections down the left with the registers beside them', function () {
    $sections = Livewire::test(WorkforceDesk::class)->instance()->deskSections();
    $html = $this->get(WorkforceDesk::getUrl())->assertOk()->getContent();

    expect(browseLayoutOf($html)['labels'])->toBe(array_column($sections, 'label'))
        ->and($html)->toContain(e(ExitCaseResource::getUrl('index', ['view' => 'resignations'])));
});
