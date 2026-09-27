<?php

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Services\Blueprints;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Level;
use App\Domain\People\Models\Skill;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Platform\Services\SettingsRepository;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->blueprints = app(Blueprints::class);
});

it('applies a bundled pack idempotently', function () {
    $first = $this->blueprints->applyPack('it-company');
    $second = $this->blueprints->applyPack('it-company');

    expect($first['policies'])->toBe(4)
        ->and(Policy::query()->count())->toBe(4)
        ->and(Policy::query()->where('code', 'IT_LEAVE')->first()->versionEffectiveOn()->setting('entitlements')[0]['days'])->toBe(21)
        ->and(Policy::query()->where('code', 'IT_LEAVE')->first()->versions()->count())->toBe(1)
        ->and(Form::query()->where('key', 'it_asset_handover')->first()->published()->first()->fields)->toHaveCount(3)
        ->and(CustomField::query()->where('key', 'laptop_asset_tag')->exists())->toBeTrue()
        ->and(Skill::query()->count())->toBe(6)
        ->and(Level::query()->count())->toBe(6)
        ->and($second['policies'])->toBe(4);
});

it('rejects unknown packs and formats', function () {
    expect(fn () => $this->blueprints->applyPack('nope'))->toThrow(ConfigurationException::class);
    expect(fn () => $this->blueprints->import(['format' => 'other']))->toThrow(ConfigurationException::class);
});

it('exports a tenant and imports it into another without employee data', function () {
    $this->blueprints->applyPack('startup');
    app(SettingsRepository::class)->set('branding.display_name', 'Acme People');
    Employee::factory()->create();

    $export = $this->blueprints->export();

    expect($export['format'])->toBe(Blueprints::FORMAT)
        ->and($export['settings']['branding.display_name'])->toBe('Acme People')
        ->and(collect($export['policies'])->pluck('code')->all())->toContain('STARTUP_LEAVE')
        ->and(json_encode($export))->not->toContain('employee_code')
        ->and($export)->not->toHaveKey('employees');

    $other = provisionTenant('Other');
    actAsTenant($other);

    $counts = $this->blueprints->import($export, 'Copied from Acme');

    expect(app(SettingsRepository::class)->get('branding.display_name'))->toBe('Acme People')
        ->and(Policy::query()->where('code', 'STARTUP_LEAVE')->first()->versionEffectiveOn()->setting('entitlements')[0]['days'])->toBe(24)
        ->and(CustomField::query()->where('key', 'tshirt_size')->exists())->toBeTrue()
        ->and(Employee::query()->count())->toBe(0)
        ->and($counts['roles'])->toBeGreaterThan(0);
});

it('lets a pack switch on configuration approval', function () {
    $this->blueprints->applyPack('enterprise');

    expect(app(FeatureFlags::class)->enabled('configuration.approval'))->toBeTrue()
        ->and(app(SettingsRepository::class)->get('configuration.approval.minimum_risk'))->toBe('medium')
        ->and(Policy::query()->where('code', 'ENT_LEAVE_SENIOR')->first()->assignmentRules()->value('priority'))->toBe(10);
});

it('re-import updates a policy only when its settings changed', function () {
    $this->blueprints->applyPack('startup');
    $policy = Policy::query()->where('code', 'STARTUP_LEAVE')->first();
    expect($policy->versions()->count())->toBe(1);

    $this->travel(2)->days();
    app(Policies::class)->draft($policy, ['entitlements' => [['leave_type_code' => 'EL', 'days' => 30]]]);
    app(Policies::class)->publish($policy, now());
    expect($policy->versionEffectiveOn()->setting('entitlements')[0]['days'])->toBe(30);

    $this->travel(2)->days();
    $this->blueprints->applyPack('startup');

    expect($policy->versions()->count())->toBe(3)
        ->and($policy->versionEffectiveOn()->setting('entitlements')[0]['days'])->toBe(24);
});
