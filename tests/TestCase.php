<?php

namespace Tests;

use App\Support\Http\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeHostResolver;

abstract class TestCase extends BaseTestCase
{
    /**
     * Test database guard (2026-11-16): with a cached config (bootstrap/cache/config.php, e.g. after `php artisan optimize`)
     * Laravel ignores phpunit.xml, so RefreshDatabase would run migrate:fresh on the real MySQL development database. It
     * wiped the local `hcm` database twice. Refuse to start instead; MySQL concurrency tests switch to their own databases
     * later, inside the test, so they still start on SQLite.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = (string) $app['config']->get('database.default');
        if ($app->configurationIsCached() || $connection !== 'sqlite') {
            throw new \RuntimeException('Refusing to run tests: '.($app->configurationIsCached()
                ? 'the configuration is cached (bootstrap/cache/config.php), so phpunit.xml is ignored and tests would run on the real database. Run `php artisan config:clear`, or set APP_CONFIG_CACHE to a missing path for the test run.'
                : "the default database connection is \"{$connection}\", not the in-memory SQLite database from phpunit.xml."));
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Production readiness closure: no real DNS in tests; the outbound SSRF guard sees a public address
        // for every name unless a test maps it (tests/Feature/Security/OutboundSsrfTest.php).
        $this->app->instance(HostResolver::class, new FakeHostResolver);
        // Experience Transformation: the panel theme and interaction script are Vite assets; tests do not
        // depend on a built manifest (public/build is not committed).
        $this->withoutVite();
    }
}
