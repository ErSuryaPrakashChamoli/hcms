<?php

namespace Tests;

use App\Support\Http\HostResolver;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
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
        $this->isolateLocalDisks();
    }

    /** This process's folder for every local disk (see isolateLocalDisks). */
    private static ?string $diskRoot = null;

    /**
     * Test storage isolation (2026-11-25): tests never write into the application's own storage/app. It holds preserved
     * statutory evidence (storage/app/private/compliance-evidence), and test runs that seed statutory packs used to
     * write into it. Every local disk rooted there points at a folder of this process under storage/framework/testing
     * instead, removed when the process ends. Storage::fake() still works as before (its own folder). The working
     * folders of jobs and downloads (App\Support\Storage\ScratchSpace) go there too.
     */
    private function isolateLocalDisks(): void
    {
        if (self::$diskRoot === null) {
            $root = self::$diskRoot = storage_path('framework/testing/disks/run-'.getmypid());
            register_shutdown_function(static fn () => (new Filesystem)->deleteDirectory($root));
        }
        $live = storage_path('app');
        $isolated = [];
        foreach ((array) $this->app['config']->get('filesystems.disks', []) as $name => $disk) {
            if (($disk['driver'] ?? null) === 'local' && str_starts_with((string) ($disk['root'] ?? ''), $live)) {
                $this->app['config']->set("filesystems.disks.{$name}.root", self::$diskRoot.'/'.$name);
                $isolated[] = $name;
            }
        }
        Storage::forgetDisk($isolated);
        // The working folders of jobs and downloads (App\Support\Storage\ScratchSpace) too.
        $this->app['config']->set('peopleos.storage.scratch_path', self::$diskRoot.'/scratch');
    }
}
