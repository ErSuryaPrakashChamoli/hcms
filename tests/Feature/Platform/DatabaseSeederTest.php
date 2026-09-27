<?php

use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;

it('seeds the demo tenant and is safe to run twice', function () {
    $this->seed();
    $this->seed();

    $tenant = app(TenantContext::class)->bypass(fn () => Tenant::query()->where('slug', 'demo')->firstOrFail());

    app(TenantContext::class)->runAs($tenant, function () {
        expect(OrganisationNode::query()->count())->toBe(12)
            ->and(OrganisationNode::query()->whereNull('parent_id')->count())->toBe(2)
            ->and(OrganisationNode::query()->max('depth'))->toBe(3);
    });

    expect(Tenant::query()->count())->toBe(1);
});
