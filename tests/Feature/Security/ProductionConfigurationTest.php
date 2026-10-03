<?php

use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Services\SettingsRepository;
use App\Providers\AppServiceProvider;
use App\Support\Observability\ProductionConfigValidator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\TenantAwareTestJob;

/*
 * Production readiness closure (blockers 6 and 7, code side): the configuration validator, proxy trust,
 * production debug neutralisation, and readiness that refuses traffic on unsafe production
 * configuration. Redis itself is not available here; these tests prove detection, not a live Redis.
 */

/** Validate as if the default connection were MySQL, restoring it at once (the test database stays SQLite). */
function validateOnMysql(bool $asProduction = false): array
{
    $previous = config('database.default');
    config(['database.default' => 'mysql']);
    try {
        return app(ProductionConfigValidator::class)->validate($asProduction);
    } finally {
        config(['database.default' => $previous]);
    }
}

function productionLikeConfig(): void
{
    config([
        'app.debug' => false, 'app.url' => 'https://people.acme-hr.com', 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'app.cipher' => 'AES-256-CBC',
        'database.connections.mysql.host' => 'db.internal', 'database.connections.mysql.database' => 'peopleos',
        'database.connections.mysql.username' => 'peopleos_app', 'database.connections.mysql.password' => 'Str0ng-Database-Passw0rd!',
        'cache.default' => 'database', 'session.driver' => 'database', 'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax', 'session.encrypt' => true,
        'queue.default' => 'database', 'queue.connections.database.retry_after' => 3900, 'queue.failed.driver' => 'database-uuids',
        'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.mailprovider.net', 'mail.from.address' => 'hr-no-reply@acme-hr.com',
        'filesystems.disks.s3' => array_merge(config('filesystems.disks.s3'), ['key' => 'AKIAPRODKEY', 'secret' => 'prod-aws-secret-value', 'region' => 'ap-south-1', 'bucket' => 'acme-peopleos']),
        'peopleos.documents.disk' => 's3', 'peopleos.storage.staging_disk' => 's3', 'peopleos.storage.compliance_disk' => 's3', 'peopleos.enterprise.warehouse_disk' => 's3',
        'filesystems.default' => 's3', 'peopleos.storage.shared_required' => true,
        'peopleos.http.trusted_proxies' => '10.0.0.0/8', 'peopleos.health.token' => str_repeat('h', 40),
        'logging.default' => 'stack', 'logging.channels.stack.channels' => ['daily'], 'logging.channels.daily.level' => 'info',
    ]);
}

it('finds missing, insecure, invalid and incompatible settings without printing any value', function () {
    config(['database.connections.mysql.password' => 'super-secret-db-pw', 'filesystems.disks.s3.secret' => 'aws-secret-zzz', 'peopleos.ai.anthropic.key' => 'sk-ant-should-not-print']);
    $findings = collect(app(ProductionConfigValidator::class)->validate(asProduction: true));

    expect($findings->where('severity', 'error')->pluck('key')->all())->toContain('APP_ENV', 'APP_URL', 'DB_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER', 'SESSION_SECURE_COOKIE', 'QUEUE_CONNECTION', 'MAIL_MAILER', 'PEOPLEOS_HEALTH_TOKEN')
        ->and($findings->pluck('category')->unique()->all())->toContain('missing', 'insecure', 'invalid', 'incompatible')
        ->and(json_encode($findings))->not->toContain('super-secret-db-pw')->not->toContain('aws-secret-zzz')->not->toContain('sk-ant-should-not-print');

    // Outside production (without --as-production) nothing is an error: development keeps working.
    expect(collect(app(ProductionConfigValidator::class)->validate())->where('severity', 'error'))->toBeEmpty();
});

it('passes a complete production configuration, and flags each break in it', function () {
    productionLikeConfig();
    app()->detectEnvironment(fn () => 'production');
    $errors = fn () => collect(validateOnMysql())->where('severity', 'error')->pluck('key')->all();
    expect($errors())->toBe([]);

    config(['filesystems.disks.s3.bucket' => null]);
    expect($errors())->toContain('AWS_BUCKET');
    config(['filesystems.disks.s3.bucket' => 'acme-peopleos', 'peopleos.storage.staging_disk' => 'local']);
    expect($errors())->toContain('PEOPLEOS_STAGING_DISK');
    config(['peopleos.storage.staging_disk' => 's3', 'cache.default' => 'redis', 'database.redis.client' => 'phpredis']);
    expect($errors())->toContain(extension_loaded('redis') ? 'REDIS_PASSWORD' : 'REDIS_CLIENT');
    config(['cache.default' => 'database', 'app.debug' => true, 'logging.channels.daily.tap' => []]);
    expect($errors())->toContain('APP_DEBUG', 'LOG_CHANNEL');
    app()->detectEnvironment(fn () => 'testing');
});

it('exits non-zero from the validator command on errors and prints keys, not values', function () {
    config(['database.connections.mysql.password' => 'super-secret-db-pw']);
    expect(Artisan::call('peopleos:config:validate', ['--as-production' => true]))->toBe(1);
    expect(Artisan::output())->toContain('APP_URL')->not->toContain('super-secret-db-pw');
});

it('never runs production with debug on, whatever APP_DEBUG says', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['app.debug' => true]);
    (new AppServiceProvider(app()))->register();
    expect(config('app.debug'))->toBeFalse();
    app()->detectEnvironment(fn () => 'testing');
});

it('trusts forwarded headers only from the configured proxies', function () {
    Route::get('/__closure/scheme', fn () => response()->json(['secure' => request()->isSecure(), 'ip' => request()->ip()]));

    // Not trusted: a client cannot claim https or spoof its address.
    TrustProxies::flushState();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '1.2.3.4'])
        ->getJson('/__closure/scheme')->assertJson(['secure' => false, 'ip' => '203.0.113.9']);

    config(['peopleos.http.trusted_proxies' => '10.0.0.0/8']);
    (new ReflectionMethod(AppServiceProvider::class, 'trustConfiguredProxies'))->invoke(new AppServiceProvider(app()));
    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.7'])
        ->getJson('/__closure/scheme')->assertJson(['secure' => true, 'ip' => '198.51.100.7']);
    TrustProxies::flushState();
});

it('reports configuration safety and distributed locks in readiness, and refuses traffic on unsafe production configuration', function () {
    config(['peopleos.health.token' => 'probe-token-1234567890abcdef']);
    $ready = $this->withHeaders(['X-Health-Token' => 'probe-token-1234567890abcdef'])->getJson('/health/ready')->json();
    expect($ready['checks'])->toHaveKeys(['configuration', 'locks'])->and($ready['checks']['locks']['status'])->toBe('ok')
        ->and($ready['checks']['configuration']['critical'])->toBeFalse();

    app()->detectEnvironment(fn () => 'production');
    $this->flushHeaders()->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.configuration.status', 'fail');
    productionLikeConfig();
    expect(collect(app(ProductionConfigValidator::class)->validate())->where('severity', 'error')->pluck('key')->all())->toBe(['DB_CONNECTION'])
        ->and(collect(validateOnMysql())->where('severity', 'error')->all())->toBe([]);
    app()->detectEnvironment(fn () => 'testing');
});

it('keeps tenants apart on a real queue round-trip and in the shared cache', function () {
    $alpha = provisionTenant('Alpha');
    $beta = provisionTenant('Beta');
    config(['queue.default' => 'database']);

    // Dispatched in Alpha, worked by a real worker while another tenant is bound: the job re-binds Alpha.
    actAsTenant($alpha);
    TenantAwareTestJob::dispatch('Queued In Alpha');
    actAsTenant($beta);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);
    actAsTenant(null);
    $company = Company::query()->withoutGlobalScopes()->where('name', 'Queued In Alpha')->sole();
    expect($company->tenant_id)->toBe($alpha->id);

    // A tenant-aware job that lost its tenant is refused by a real worker and recorded as failed, never run unscoped.
    $job = new TenantAwareTestJob('Unbound');
    $job->tenantId = null;
    dispatch($job);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true, '--tries' => 1]);
    expect(Company::query()->withoutGlobalScopes()->where('name', 'Unbound')->exists())->toBeFalse()
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    // Shared cache: per-tenant keys, so one tenant's settings never answer for another.
    app(TenantContext::class)->runAs($alpha, fn () => app(SettingsRepository::class)->set('employee.code.prefix', 'ALP'));
    expect(app(TenantContext::class)->runAs($beta, fn () => app(SettingsRepository::class)->get('employee.code.prefix')))->toBe('EMP')
        ->and(app(TenantContext::class)->runAs($alpha, fn () => app(SettingsRepository::class)->get('employee.code.prefix')))->toBe('ALP');
});
