<?php

use App\Filament\Pages\AdminCentre;

/* SaaS.2: Admin Centre recognises the real permission keys, and the page refuses everyone else server-side. */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
});

it('opens for holders of the custom-field and feature-flag view keys', function (string $key) {
    $this->actingAs(tenantUser($this->tenant, [$key]));

    expect(AdminCentre::canAccess())->toBeTrue();
    $this->get(AdminCentre::getUrl())->assertOk();
})->with(['custom_field.view', 'features.view']);

it('refuses a user without an administration key, even on a direct request', function () {
    $this->actingAs(tenantUser($this->tenant, ['leave.apply']));

    expect(AdminCentre::canAccess())->toBeFalse();
    $this->get(AdminCentre::getUrl())->assertForbidden();
});
