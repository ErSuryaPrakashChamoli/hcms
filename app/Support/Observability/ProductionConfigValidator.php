<?php

namespace App\Support\Observability;

use Filament\FilamentManager;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Predis\Client;
use Throwable;

/**
 * Production readiness closure: the configuration validator (`peopleos:config:validate`, readiness,
 * and `/health/ready` in production).
 *
 * Each finding names the configuration key, a category and the rule broken:
 * - missing: a required value is absent;
 * - insecure: a value is unsafe for production traffic;
 * - invalid: a value cannot work;
 * - incompatible: two settings cannot work together, or a required component is absent.
 * Findings **never** contain a configured value: no secrets, hosts or URLs are echoed.
 *
 * Severity: in production (or with $asProduction) every finding is an error, except advisories, which
 * stay warnings. Elsewhere everything is a warning, so development keeps working.
 */
final class ProductionConfigValidator
{
    private const WEAK_PASSWORDS = ['', 'password', 'secret', 'root', 'admin', 'changeme', 'laravel', 'mysql', '123456'];

    /** @var list<array{key: string, category: string, severity: string, message: string}> */
    private array $findings = [];

    private bool $production = false;

    /** @return list<array{key: string, category: string, severity: string, message: string}> */
    public function validate(bool $asProduction = false): array
    {
        $this->findings = [];
        $this->production = $asProduction || app()->environment('production');

        $this->application();
        $this->database();
        $this->redis();
        $this->cacheSessionQueue();
        $this->mail();
        $this->storage();
        $this->http();
        $this->logging();
        $this->operations();

        return $this->findings;
    }

    /** @return array{errors: int, warnings: int} */
    public function summary(bool $asProduction = false): array
    {
        $f = collect($this->validate($asProduction));

        return ['errors' => $f->where('severity', 'error')->count(), 'warnings' => $f->where('severity', 'warning')->count()];
    }

    private function application(): void
    {
        $this->require(app()->environment('production'), 'APP_ENV', 'insecure', 'The environment must be "production".');
        $this->require(! config('app.debug'), 'APP_DEBUG', 'insecure', 'Debug mode must be off (production forces it off at boot, but the setting must be fixed).');
        $key = (string) config('app.key');
        $this->require($key !== '', 'APP_KEY', 'missing', 'An application key is required (encrypted columns and signed URLs depend on it).');
        if ($key !== '') {
            $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
            $this->require($raw !== false && strlen((string) $raw) === 32, 'APP_KEY', 'invalid', 'The application key must be 32 bytes (base64: prefix for an encoded key).');
        }
        $this->require(in_array(strtolower((string) config('app.cipher')), ['aes-256-cbc', 'aes-256-gcm'], true), 'APP_CIPHER', 'insecure', 'Use AES-256-CBC or AES-256-GCM.');
        $url = (string) config('app.url');
        $this->require(str_starts_with($url, 'https://'), 'APP_URL', 'insecure', 'The public URL must use https (signed links and secure cookies depend on it).');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $this->require(! in_array($host, ['localhost', '127.0.0.1', ''], true) && ! str_ends_with($host, '.test'), 'APP_URL', 'invalid', 'The public URL must be the real public host.');
    }

    private function database(): void
    {
        $name = (string) config('database.default');
        $c = (array) config("database.connections.{$name}", []);
        $this->require(($c['driver'] ?? null) === 'mysql', 'DB_CONNECTION', 'incompatible', 'Production runs on MySQL 8 (the concurrency guarantees were proven on MySQL).');
        if (($c['driver'] ?? null) === 'mysql') {
            $this->require(filled($c['host'] ?? null) || filled($c['unix_socket'] ?? null), 'DB_HOST', 'missing', 'A database host (or socket) is required.');
            $this->require(filled($c['database'] ?? null), 'DB_DATABASE', 'missing', 'A database name is required.');
            $this->require(filled($c['username'] ?? null) && ($c['username'] ?? null) !== 'root', 'DB_USERNAME', 'insecure', 'Use a dedicated application user, not root.');
            $this->require(! in_array(strtolower((string) ($c['password'] ?? '')), self::WEAK_PASSWORDS, true) && strlen((string) ($c['password'] ?? '')) >= 12, 'DB_PASSWORD', 'insecure', 'The database password is missing, too short or a common default.');
        }
    }

    private function redis(): void
    {
        $uses = array_filter(['CACHE_STORE' => config('cache.default') === 'redis', 'QUEUE_CONNECTION' => config('queue.default') === 'redis', 'SESSION_DRIVER' => config('session.driver') === 'redis']);
        if ($uses === []) {
            return;
        }
        $client = (string) config('database.redis.client', 'phpredis');
        $this->require($client !== 'phpredis' || extension_loaded('redis'), 'REDIS_CLIENT', 'incompatible', 'The phpredis client needs the redis PHP extension ('.implode(', ', array_keys($uses)).' use Redis).');
        $this->require($client !== 'predis' || class_exists(Client::class), 'REDIS_CLIENT', 'incompatible', 'The predis client needs the predis/predis package.');
        $this->require(filled(config('database.redis.default.host')) || filled(config('database.redis.default.url')), 'REDIS_HOST', 'missing', 'A Redis host is required.');
        $this->require(filled(config('database.redis.default.password')) || filled(config('database.redis.default.url')), 'REDIS_PASSWORD', 'insecure', 'Redis should require authentication.');
    }

    private function cacheSessionQueue(): void
    {
        $cache = (string) config('cache.default');
        $this->require(in_array($cache, ['redis', 'database', 'memcached', 'dynamodb'], true), 'CACHE_STORE', 'incompatible', 'A shared cache store is required (scheduler locks, rate limits, health heartbeat).');
        try {
            $this->require(Cache::store()->getStore() instanceof LockProvider, 'CACHE_STORE', 'incompatible', 'The cache store must support atomic locks (onOneServer, withoutOverlapping).');
        } catch (Throwable) {
            $this->add('CACHE_STORE', 'invalid', 'The cache store cannot be created.');
        }
        $session = (string) config('session.driver');
        $this->require(in_array($session, ['redis', 'database'], true), 'SESSION_DRIVER', 'incompatible', 'Sessions must live in a shared store (redis or database).');
        $this->require((bool) config('session.secure'), 'SESSION_SECURE_COOKIE', 'insecure', 'Session cookies must be Secure.');
        $this->require((bool) config('session.http_only', true), 'SESSION_HTTP_ONLY', 'insecure', 'Session cookies must be HttpOnly.');
        $this->require(in_array(strtolower((string) config('session.same_site')), ['lax', 'strict'], true), 'SESSION_SAME_SITE', 'insecure', 'Session cookies must be SameSite=lax or strict.');
        $this->advise((bool) config('session.encrypt'), 'SESSION_ENCRYPT', 'Encrypting the session payload is recommended.');

        $queue = (string) config('queue.default');
        $this->require(! in_array($queue, ['sync', 'null', 'deferred', 'background'], true), 'QUEUE_CONNECTION', 'incompatible', 'Production needs a real queue (redis or database) with supervised workers.');
        $retry = (int) config("queue.connections.{$queue}.retry_after", 0);
        $this->require(in_array($queue, ['sync', 'null'], true) || $retry > 3600, 'QUEUE_RETRY_AFTER', 'invalid', 'retry_after must exceed the longest job timeout (3600 s).');
        $this->require(filled(config('queue.failed.driver')) && config('queue.failed.driver') !== 'null', 'QUEUE_FAILED_DRIVER', 'missing', 'Failed jobs must be recorded.');
    }

    private function mail(): void
    {
        $mailer = (string) config('mail.default');
        $this->require(! in_array($mailer, ['log', 'array'], true), 'MAIL_MAILER', 'incompatible', 'A real mail transport is required.');
        $from = (string) config('mail.from.address');
        $this->require($from !== '' && ! str_contains($from, 'example.com'), 'MAIL_FROM_ADDRESS', 'missing', 'A monitored sender address is required.');
        if ($mailer === 'smtp') {
            $this->require(filled(config('mail.mailers.smtp.host')) && config('mail.mailers.smtp.host') !== '127.0.0.1', 'MAIL_HOST', 'missing', 'An SMTP host is required.');
        }
    }

    private function storage(): void
    {
        $roles = [
            'PEOPLEOS_DOCUMENTS_DISK' => (string) config('peopleos.documents.disk', 'local'),
            'PEOPLEOS_STAGING_DISK' => (string) config('peopleos.storage.staging_disk', 'local'),
            'PEOPLEOS_COMPLIANCE_DISK' => (string) config('peopleos.storage.compliance_disk', 'local'),
            'PEOPLEOS_WAREHOUSE_DISK' => (string) config('peopleos.enterprise.warehouse_disk', 'local'),
            'FILESYSTEM_DISK' => (string) config('filesystems.default', 'local'),
        ];
        foreach ($roles as $env => $disk) {
            $config = config("filesystems.disks.{$disk}");
            if (! is_array($config)) {
                $this->add($env, 'invalid', 'The configured disk does not exist.');

                continue;
            }
            $this->require(($config['visibility'] ?? 'private') !== 'public' && $disk !== 'public', $env, 'insecure', 'Private files must not use a public disk.');
            if (($config['driver'] ?? null) === 's3') {
                $this->require(class_exists(AwsS3V3Adapter::class), $env, 'incompatible', 'The S3 adapter (league/flysystem-aws-s3-v3) is not installed.');
                foreach (['key' => 'AWS_ACCESS_KEY_ID', 'secret' => 'AWS_SECRET_ACCESS_KEY', 'region' => 'AWS_DEFAULT_REGION', 'bucket' => 'AWS_BUCKET'] as $field => $var) {
                    $this->require(filled($config[$field] ?? null), $var, 'missing', 'Required for the '.$env.' object storage role.');
                }
                $this->require(($config['throw'] ?? false) === true, $env, 'insecure', 'Object storage failures must throw, never silently return false.');
            }
            if ((bool) config('peopleos.storage.shared_required')) {
                $this->require(($config['driver'] ?? null) !== 'local', $env, 'incompatible', 'Shared storage is required (more than one node), but this role uses a local disk.');
            }
        }
        $this->require(! config('filesystems.disks.local.serve'), 'filesystems.disks.local.serve', 'insecure', 'The local private disk must not serve files directly.');
    }

    private function http(): void
    {
        $this->advise(filled(config('peopleos.http.trusted_proxies')), 'PEOPLEOS_TRUSTED_PROXIES', 'Behind a TLS-terminating load balancer, trust its address(es) or the app will see plain http.');
        $this->require(! (config('cors.supports_credentials') && in_array('*', (array) config('cors.allowed_origins', []), true)), 'CORS', 'insecure', 'Credentialed CORS must not allow every origin.');
        $panel = app(FilamentManager::class)->getPanel('admin');
        $this->require(in_array(PreventRequestForgery::class, $panel->getMiddleware(), true), 'CSRF', 'insecure', 'The admin panel must run CSRF protection.');
        $this->require((int) config('peopleos.api.rate_limit_per_minute', 120) > 0 && (int) config('peopleos.ai.rate_limit_per_minute', 20) > 0, 'RATE_LIMITS', 'invalid', 'API and AI rate limits must be positive.');
        $this->require(! config('peopleos.outbound.allow_http'), 'PEOPLEOS_OUTBOUND_ALLOW_HTTP', 'insecure', 'Outbound requests to tenant destinations must use https.');
        $this->advise(config('peopleos.outbound.allowed_hosts', []) === [], 'PEOPLEOS_OUTBOUND_ALLOWED_HOSTS', 'Outbound SSRF exemptions are configured; review them.');
    }

    private function logging(): void
    {
        $default = (string) config('logging.default');
        $channels = $default === 'stack' ? (array) config('logging.channels.stack.channels', []) : [$default];
        foreach ($channels as $channel) {
            $this->require(in_array(RedactSensitiveLogData::class, (array) config("logging.channels.{$channel}.tap", []), true), 'LOG_CHANNEL', 'insecure', "Log channel [{$channel}] must carry the redaction tap.");
            $this->require(config("logging.channels.{$channel}.level", 'debug') !== 'debug', 'LOG_LEVEL', 'insecure', 'Production logs must not run at debug level.');
        }
    }

    private function operations(): void
    {
        $token = (string) config('peopleos.health.token');
        $this->require(strlen($token) >= 24, 'PEOPLEOS_HEALTH_TOKEN', 'missing', 'A long random monitoring token is required for readiness details.');
        $this->require((int) config('peopleos.health.heartbeat_max_age', 180) > 60, 'PEOPLEOS_HEARTBEAT_MAX_AGE', 'invalid', 'The heartbeat age limit must exceed the one-minute schedule.');
        // SaaS.2: platform operators can reach every tenant, so production never runs without operator MFA.
        $this->require((bool) config('peopleos.security.platform_mfa_required', true), 'PEOPLEOS_PLATFORM_MFA_REQUIRED', 'insecure', 'Platform operators must use multi-factor authentication in production.');
        if (config('peopleos.ai.provider', 'none') === 'anthropic') {
            $this->require(filled(config('peopleos.ai.anthropic.key')), 'ANTHROPIC_API_KEY', 'missing', 'The configured AI provider needs its key.');
        }
    }

    private function require(bool $ok, string $key, string $category, string $message): void
    {
        if (! $ok) {
            $this->add($key, $category, $message);
        }
    }

    private function advise(bool $ok, string $key, string $message): void
    {
        if (! $ok) {
            $this->findings[] = ['key' => $key, 'category' => 'advisory', 'severity' => 'warning', 'message' => $message];
        }
    }

    private function add(string $key, string $category, string $message): void
    {
        $this->findings[] = ['key' => $key, 'category' => $category, 'severity' => $this->production ? 'error' : 'warning', 'message' => $message];
    }
}
