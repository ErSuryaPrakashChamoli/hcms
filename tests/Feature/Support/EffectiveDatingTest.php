<?php

use App\Domain\Organisation\Models\Company;

beforeEach(function () {
    actAsTenant(provisionTenant());

    $this->past = Company::factory()->create(['name' => 'Past', 'effective_from' => '2024-01-01', 'effective_to' => '2024-12-31']);
    $this->current = Company::factory()->create(['name' => 'Current', 'effective_from' => '2025-01-01', 'effective_to' => null]);
    $this->future = Company::factory()->create(['name' => 'Future', 'effective_from' => '2030-01-01', 'effective_to' => null]);
    $this->open = Company::factory()->create(['name' => 'Open', 'effective_from' => null, 'effective_to' => null]);
});

it('selects records effective on a given date', function () {
    expect(Company::query()->effectiveOn('2024-06-15')->pluck('name')->all())->toEqualCanonicalizing(['Past', 'Open'])
        ->and(Company::query()->effectiveOn('2026-09-26')->pluck('name')->all())->toEqualCanonicalizing(['Current', 'Open'])
        ->and(Company::query()->effectiveOn('2031-01-01')->pluck('name')->all())->toEqualCanonicalizing(['Current', 'Future', 'Open']);
});

it('exposes current, future and expired scopes', function () {
    $this->travelTo('2026-09-26');

    expect(Company::query()->currentlyEffective()->pluck('name')->all())->toEqualCanonicalizing(['Current', 'Open'])
        ->and(Company::query()->futureDated()->pluck('name')->all())->toBe(['Future'])
        ->and(Company::query()->expired()->pluck('name')->all())->toBe(['Past']);
});

it('answers isEffectiveOn per record', function () {
    expect($this->past->isEffectiveOn('2024-12-31'))->toBeTrue()
        ->and($this->past->isEffectiveOn('2025-01-01'))->toBeFalse()
        ->and($this->open->isEffectiveOn('1999-01-01'))->toBeTrue()
        ->and($this->future->isEffectiveOn('2029-12-31'))->toBeFalse();
});
