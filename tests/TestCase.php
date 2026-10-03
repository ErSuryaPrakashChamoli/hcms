<?php

namespace Tests;

use App\Support\Http\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeHostResolver;

abstract class TestCase extends BaseTestCase
{
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
