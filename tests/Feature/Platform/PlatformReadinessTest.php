<?php

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Filament\Pages\PlatformReadinessPage;
use App\Support\Observability\PlatformReadiness;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
 * Phase 14.8: the readiness report combines configuration, health, audit chains, the statutory
 * production gate and operator evidence, and never declares production readiness by itself.
 */

beforeEach(function () {
    Storage::fake('local');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
});

it('keeps the statutory gate BLOCKED while rules are unverified and never declares production readiness', function () {
    $report = app(PlatformReadiness::class)->report();
    $statutory = collect($report['checks'])->firstWhere('area', 'statutory');

    expect($report['summary']['statutory']['blocked'])->toBeTrue()
        ->and($report['summary']['statutory']['rules'])->toBeGreaterThan(0)
        ->and($report['summary']['statutory']['verified'])->toBe(0)
        ->and($statutory['status'])->toBe(PlatformReadiness::BLOCKED)
        ->and($report['summary']['production_ready'])->toBeFalse()
        ->and($report['summary']['verdict'])->toContain('NOT DECLARED')
        ->and(collect($report['checks'])->where('area', 'operations')->pluck('status')->unique()->all())->toBe([PlatformReadiness::UNVERIFIED])
        ->and(collect($report['checks'])->firstWhere('area', 'audit')['status'])->toBe(PlatformReadiness::PASS);
});

it('fails unsafe production configuration and exits non-zero', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['app.debug' => true, 'queue.default' => 'sync', 'cache.default' => 'array', 'mail.default' => 'log', 'filesystems.disks.local.serve' => true]);
    $report = app(PlatformReadiness::class)->report(false);
    $failed = collect($report['checks'])->where('status', PlatformReadiness::FAIL)->pluck('check')->all();

    expect($failed)->toContain('APP_DEBUG is off', 'Queue is not sync', 'Cache store is shared (scheduler locks, heartbeat)', 'Mailer is a real transport', 'Local private disk does not serve files directly')
        ->and($report['summary']['production_ready'])->toBeFalse();
    expect(Artisan::call('peopleos:readiness', ['--skip-audit' => true]))->toBe(1);
    expect(Artisan::output())->toContain('Statutory production gate: BLOCKED')->toContain('NOT DECLARED');
    app()->detectEnvironment(fn () => 'testing');
});

it('fails production readiness while a platform operator who can sign in has no MFA enrolled (SaaS.11)', function () {
    $check = fn () => collect(app(PlatformReadiness::class)->report(false)['checks'])->firstWhere('check', 'Every platform operator has MFA enrolled');
    $enrolled = platformAdmin();
    $enrolled->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $unenrolled = platformAdmin();
    platformAdmin()->forceFill(['status' => UserStatus::Suspended])->save(); // cannot sign in: not counted

    expect($check())->toMatchArray(['status' => PlatformReadiness::WARN, 'detail' => '1 of 2 not enrolled']);
    app()->detectEnvironment(fn () => 'production');
    expect($check()['status'])->toBe(PlatformReadiness::FAIL);

    $unenrolled->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    expect($check())->toMatchArray(['status' => PlatformReadiness::PASS, 'detail' => 'all enrolled']);
    app()->detectEnvironment(fn () => 'testing');
});

it('fails production readiness while a demo or test account can sign in (SaaS.13)', function () {
    $check = fn () => collect(app(PlatformReadiness::class)->report(false)['checks'])->firstWhere('check', 'No demo or test sign-in accounts (reserved e-mail domains)');
    User::query()->pluck('id')->each(fn (int $id) => User::query()->whereKey($id)->update(['email' => "person{$id}@acme-hr.com"])); // factories use example.* addresses
    expect($check())->toMatchArray(['status' => PlatformReadiness::PASS, 'detail' => 'none']);

    $demo = User::factory()->create(['email' => 'admin@demo.local']);
    User::factory()->create(['email' => 'old.persona@demo.local', 'status' => UserStatus::Suspended]); // cannot sign in: not counted
    app()->detectEnvironment(fn () => 'production');
    expect($check())->toMatchArray(['status' => PlatformReadiness::FAIL, 'detail' => '1 account(s) on reserved domains (.local, .test, example.*)'])
        ->and(json_encode($check()))->not->toContain('demo.local');
    $demo->forceFill(['email' => 'admin@acme-hr.com'])->save();
    expect($check()['status'])->toBe(PlatformReadiness::PASS);
    app()->detectEnvironment(fn () => 'testing');
});

it('shows the readiness page to platform administrators only', function () {
    $this->actingAs(platformAdmin());
    $this->get(PlatformReadinessPage::getUrl())->assertOk()->assertSee('Statutory production gate')->assertSee('BLOCKED')->assertSee('NOT DECLARED');
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->get(PlatformReadinessPage::getUrl())->assertForbidden();
});
