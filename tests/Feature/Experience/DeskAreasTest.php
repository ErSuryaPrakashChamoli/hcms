<?php

use App\Domain\Experience\Services\ExperienceNavigation;
use App\Filament\Pages\AssetDesk;
use App\Filament\Pages\EngageDesk;
use App\Filament\Pages\PayrollDesk;
use App\Filament\Pages\PerformanceDesk;
use App\Filament\Pages\TimeOfficeDesk;
use App\Filament\Pages\WorkforceDesk;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use Filament\Facades\Filament;

/*
| A desk is the front page of the area it sits in, not a rail area of its own: the rail lists Time & leave once (not
| also "Time office desk"), the area opens on its desk for operators and the desk leads the area bar. People who
| cannot open the desk open the area as before.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    $this->admin = tenantUser($this->tenant, ['*']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actAsTenant(null);
});

it('lists each area once in the rail and opens it on its desk', function () {
    $this->actingAs($this->admin);
    actAsTenant($this->tenant);
    $nav = app(ExperienceNavigation::class);
    $rail = fn (string $section) => collect($nav->children($this->admin, $section))->mapWithKeys(fn (array $a) => [$a['label'] => $a['url']]);

    $people = $rail('people');
    expect($people->keys()->filter(fn (string $label) => str_ends_with($label, ' desk'))->all())->toBe([])
        ->and($people['Lifecycle'])->toBe(WorkforceDesk::getUrl())
        ->and($people['Time & leave'])->toBe(TimeOfficeDesk::getUrl())
        ->and($people['Growth'])->toBe(PerformanceDesk::getUrl())
        ->and($people['Pay'])->toBe(PayrollDesk::getUrl())
        ->and($rail('organisation')->all())->not->toHaveKey('Asset desk')->toHaveKey('Workforce')
        ->and($rail('organisation')['Assets'])->toBe(AssetDesk::getUrl())
        ->and($rail('communication')['Engagement'])->toBe(EngageDesk::getUrl())
        // The workforce desk runs the employee lifecycle; "Workforce" is the Organisation area of positions and plans.
        ->and(WorkforceDesk::getNavigationLabel())->toBe('Lifecycle desk');
    actAsTenant(null);
});

it('leads the area bar with the desk and shows the area on the desk itself', function () {
    $this->actingAs($this->admin);
    $row = function (string $html): array {
        $nav = substr($html, (int) strpos($html, 'class="pos-spacebar"'));
        preg_match_all('/<a href="([^"]+)"[^>]*class="pos-spacebar-link"/', substr($nav, 0, (int) strpos($nav, '</nav>')), $m);

        return $m[1];
    };

    expect(array_slice($row($this->get(LeaveRequestResource::getUrl('index'))->assertOk()->getContent()), 0, 2))
        ->toBe([LeaveRequestResource::getUrl('index'), TimeOfficeDesk::getUrl()]);

    $desk = $this->get(TimeOfficeDesk::getUrl())->assertOk()->assertSee('All in Time & leave')->getContent();
    expect($row($desk)[0])->toBe(TimeOfficeDesk::getUrl())
        ->and($row($desk))->toContain(LeaveRequestResource::getUrl('index'));
});

it('opens the area as before for people who cannot open its desk', function () {
    $clerk = tenantUser($this->tenant, ['leave.view', 'employee.view']);
    $this->actingAs($clerk);
    actAsTenant($this->tenant);

    $people = collect(app(ExperienceNavigation::class)->children($clerk, 'people'))->mapWithKeys(fn (array $a) => [$a['label'] => $a['url']]);
    expect(TimeOfficeDesk::canAccess())->toBeFalse()->and(WorkforceDesk::canAccess())->toBeFalse()
        ->and($people['Time & leave'])->toBe(LeaveRequestResource::getUrl('index'))
        ->and($people['Lifecycle'])->toBe(EmployeeResource::getUrl('index'));
    actAsTenant(null);
});
