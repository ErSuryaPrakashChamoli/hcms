<?php

use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Filament\Pages\OrganisationMap;
use Livewire\Livewire;

/*
| UX.15.11: the organisation map shows relationships beyond the line (dotted, functional, matrix,
| support) from reporting_relationships, only between people the viewer may see, and peeks.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['name' => 'Delhi']);
    $this->pune = Location::factory()->create(['name' => 'Pune']);
    $hire = fn (string $first, Location $loc, ?Employee $m = null) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Map'], ['joining_date' => '2024-01-01'], ['company_id' => $company->id, 'location_id' => $loc->id], $m?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->ceo = $hire('Chandra', $this->delhi);
    $this->lead = $hire('Lata', $this->delhi, $this->ceo);
    $this->dev = $hire('Dev', $this->delhi, $this->lead);
    $this->far = $hire('Farah', $this->pune, $this->ceo);
    app(ChangeManagerAction::class)->handle($this->dev, $this->far, 'dotted', now()->subDays(3)->toDateString(), 'test');
    app(ChangeManagerAction::class)->handle($this->dev, $this->ceo, 'project', now()->subDays(3)->toDateString(), 'test');
    actAsTenant(null);
});

it('shows relationships beyond the line, named by kind, in both directions', function () {
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));

    $rel = Livewire::test(OrganisationMap::class)->instance()->relations;
    expect(collect($rel[$this->dev->id])->pluck('kind', 'name')->all())->toMatchArray(['Farah Map' => 'Dotted line', 'Chandra Map' => 'Matrix'])
        ->and(collect($rel[$this->far->id])->first())->toMatchArray(['id' => $this->dev->id, 'direction' => 'from']);

    Livewire::test(OrganisationMap::class)->set('relationsOn', false)->tap(fn ($c) => expect($c->instance()->relations)->toBe([]));
});

it('never draws a relationship to someone outside the viewer\'s scope', function () {
    actAsTenant($this->tenant);
    $delhiHr = tenantUser($this->tenant, ['employee.view']);
    app(AccessScopes::class)->assign($delhiHr, ['location' => [$this->delhi->id]], 'Delhi only');
    $this->actingAs($delhiHr);

    $rel = Livewire::test(OrganisationMap::class)->instance()->relations;
    expect(collect($rel[$this->dev->id] ?? [])->pluck('name')->all())->toContain('Chandra Map')->not->toContain('Farah Map')
        ->and($rel)->not->toHaveKey($this->far->id);
});
