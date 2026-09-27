<?php

use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Services\Blueprints;
use App\Domain\Configuration\Services\ConfigurationChanges;
use App\Domain\Organisation\Models\Department;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\ConfigurationPacks;
use App\Filament\Resources\ConfigurationChanges\ConfigurationChangeResource;
use App\Filament\Resources\ConfigurationChanges\Pages\ViewConfigurationChange;
use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Resources\Forms\FormResource;
use App\Filament\Resources\Policies\PolicyResource;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    app(Blueprints::class)->applyPack('it-company');
    $this->admin = tenantUser($this->tenant, ['*']);
    actAsTenant(null);
    $this->actingAs($this->admin);
});

it('renders the configuration platform pages', function () {
    $this->get(CustomFieldResource::getUrl('index'))->assertOk()->assertSee('Laptop asset tag');
    $this->get(CustomFieldResource::getUrl('create'))->assertOk();
    $this->get(FormResource::getUrl('index'))->assertOk()->assertSee('IT asset handover');
    $this->get(FormResource::getUrl('create'))->assertOk();
    $this->get(PolicyResource::getUrl('index'))->assertOk()->assertSee('Standard leave');
    $this->get(PolicyResource::getUrl('create'))->assertOk();
    $this->get(ConfigurationChangeResource::getUrl('index'))->assertOk()->assertSee('Pending approval');
    $this->get(ConfigurationPacks::getUrl())->assertOk()->assertSee('IT Company')->assertSee('Enterprise');

    $policy = app(TenantContext::class)->runAs($this->tenant, fn () => Policy::query()->where('code', 'IT_LEAVE')->first());
    $form = app(TenantContext::class)->runAs($this->tenant, fn () => Form::query()->first());
    $this->get(PolicyResource::getUrl('edit', ['record' => $policy]))->assertOk()->assertSee('Versions');
    $this->get(FormResource::getUrl('edit', ['record' => $form]))->assertOk()->assertSee('Fill form');
});

it('approves a pending change from the change centre', function () {
    actAsTenant($this->tenant);
    app(FeatureFlags::class)->set('configuration.approval', true);
    app(SettingsRepository::class)->set('configuration.approval.minimum_risk', 'low');
    $department = Department::factory()->create(['name' => 'Finance']);
    $change = app(ConfigurationChanges::class)->propose($department, ['name' => 'Finance Ops'], 'Restructure');

    $this->get(ConfigurationChangeResource::getUrl('view', ['record' => $change]))->assertOk()->assertSee('Finance Ops')->assertSee('Restructure');

    Livewire::test(ViewConfigurationChange::class, ['record' => $change->getRouteKey()])
        ->callAction('approve', data: ['note' => 'Fine'])
        ->assertHasNoActionErrors()
        ->assertNotified('Approved');

    expect($department->fresh()->name)->toBe('Finance Ops')
        ->and($change->fresh()->review_note)->toBe('Fine');
});

it('hides configuration governance from users without the permission', function () {
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));

    $this->get(ConfigurationChangeResource::getUrl('index'))->assertForbidden();
    $this->get(ConfigurationPacks::getUrl())->assertForbidden();
    $this->get(PolicyResource::getUrl('index'))->assertForbidden();
});
