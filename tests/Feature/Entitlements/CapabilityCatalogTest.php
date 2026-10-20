<?php

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\CapabilityType;
use App\Domain\Entitlements\Enums\EnforcementClass;

/* SaaS.3: the catalogue is complete, unambiguous and keeps commercial entitlement apart from authorisation. */

it('maps every permission-key prefix and every API scope to exactly one capability', function () {
    $prefixes = collect(config('peopleos.permissions'))->flatMap(fn (array $keys) => array_keys($keys))->map(fn (string $k) => explode('.', $k)[0])->unique()->sort()->values();
    $mapped = collect(Capability::cases())->flatMap(fn (Capability $c) => $c->permissionPrefixes());
    expect($mapped->duplicates()->all())->toBe([])
        ->and($mapped->sort()->values()->all())->toBe($prefixes->all());

    $scopes = collect(array_keys(config('peopleos.api.scopes')))->sort()->values();
    $mappedScopes = collect(Capability::cases())->flatMap(fn (Capability $c) => $c->apiScopes());
    expect($mappedScopes->duplicates()->all())->toBe([])
        ->and($mappedScopes->sort()->values()->all())->toBe($scopes->all());
    foreach ($scopes as $scope) {
        expect(Capability::forApiScope($scope))->not->toBeNull();
    }
});

it('distinguishes modules, features and limits, and keeps features and limits inside a module', function () {
    foreach (Capability::cases() as $capability) {
        expect($capability->module()->type())->toBe(CapabilityType::Module);
        if ($capability->type() === CapabilityType::Module) {
            expect($capability->module())->toBe($capability);
        } else {
            expect($capability->permissionPrefixes())->toBe([])->and($capability->apiScopes())->toBe([]);
        }
        expect($capability->type() === CapabilityType::Limit)->toBe($capability->unit() !== null);
    }
    expect(collect(Capability::cases())->countBy(fn (Capability $c) => $c->type()->value)->all())->toBe(['module' => 19, 'feature' => 4, 'limit' => 8]);
});

it('never makes security or the HCM core commercial, and marks statutory and lifecycle-critical capabilities protected', function () {
    expect(Capability::Core->commercial())->toBeFalse()
        ->and(Capability::Core->permissionPrefixes())->toContain('security', 'audit', 'employee', 'document', 'workflow')
        ->and(collect(Capability::cases())->filter(fn (Capability $c) => $c->enforcement() === EnforcementClass::Protected)->map->value->values()->all())
        ->toBe(['onboarding', 'payroll', 'exit', 'active_employees.max']);
});

it('uses the catalogue enum everywhere: no raw capability strings in HCM code', function () {
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Domain/Entitlements/')) {
            continue;
        }
        if (preg_match('/->observe(Limit)?\(\s*[\'"]/', file_get_contents($file->getPathname()))) {
            $hits[] = $file->getPathname();
        }
    }
    expect($hits)->toBe([]);
});
