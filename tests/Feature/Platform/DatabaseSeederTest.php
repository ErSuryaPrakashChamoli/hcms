<?php

use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

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

it('refuses to seed demo accounts in production', function () {
    $this->app['env'] = 'production';
    try {
        // Called directly: `db:seed` would first ask the console to confirm a production run.
        expect(fn () => app(DatabaseSeeder::class)->setContainer(app())->__invoke())->toThrow(RuntimeException::class, 'refuses to run in production');
    } finally {
        $this->app['env'] = 'testing';
    }

    expect(User::query()->count())->toBe(0);
});

it('gives seeded accounts the configured password, never a fixed one', function () {
    config(['peopleos.seed.password' => 'Configured-Seed-Pass-77']);
    $this->seed();

    $platform = User::query()->where('email', 'platform@markedge.local')->sole();
    $admin = User::query()->where('email', 'admin@demo.local')->sole();
    expect(Hash::check('Configured-Seed-Pass-77', $platform->password))->toBeTrue()
        ->and(Hash::check('Configured-Seed-Pass-77', $admin->password))->toBeTrue()
        ->and(Hash::check('password', $platform->password))->toBeFalse()
        ->and($platform->email_verified_at)->not->toBeNull();
});

it('generates a fresh password when none is configured', function () {
    config(['peopleos.seed.password' => null]);
    $this->seed();

    expect(Hash::check('password', User::query()->where('email', 'platform@markedge.local')->sole()->password))->toBeFalse();
});
