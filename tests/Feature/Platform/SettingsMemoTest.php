<?php

use App\Domain\Platform\Models\TenantSetting;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobProcessing;

/* SaaS.2: tenant settings are read once per request, yet no holder ever sees a stale answer. */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
});

it('shows a change made through one instance to every other instance, including long-lived ones', function () {
    $heldBySingleton = app(SettingsRepository::class);
    expect($heldBySingleton->get('security.mfa_required'))->toBeFalse();

    app(SettingsRepository::class)->set('security.mfa_required', true, 'Policy');

    expect($heldBySingleton->get('security.mfa_required'))->toBeTrue();
});

it('forgets the memo at the end of a request and before every queued job', function () {
    $settings = app(SettingsRepository::class);
    expect($settings->get('security.session_idle_minutes'))->toBe(0);

    // A write that bypasses the repository (and its cache) is seen once the request or job boundary passes.
    TenantSetting::query()->where('key', 'security.session_idle_minutes')->update(['value' => json_encode(30)]);
    cache()->flush();
    expect($settings->get('security.session_idle_minutes'))->toBe(0);

    event(new RequestHandled(Request::create('/'), new Response));
    expect($settings->get('security.session_idle_minutes'))->toBe(30);

    TenantSetting::query()->where('key', 'security.session_idle_minutes')->update(['value' => json_encode(45)]);
    cache()->flush();
    event(new JobProcessing('sync', tap(Mockery::mock(Job::class), fn ($job) => $job->shouldReceive('payload')->andReturn([]))));
    actAsTenant($this->tenant); // what BindTenantContext does for a real job
    expect($settings->get('security.session_idle_minutes'))->toBe(45);
});
