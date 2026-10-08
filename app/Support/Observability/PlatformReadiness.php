<?php

namespace App\Support\Observability;

use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleNotice;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Schema;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Throwable;

/**
 * Phase 14 production readiness: one report combining configuration, runtime health, audit integrity,
 * the statutory production gate, and the evidence that only operators can supply.
 *
 * The report never declares PeopleOS production-ready. Its verdict is the conjunction of:
 * - configuration (no FAIL);
 * - statutory gate (every rule verified, no open notice);
 * - backup and disaster recovery (verified by restoring in the target environment);
 * - operator sign-off.
 * Software can establish only the first.
 */
final class PlatformReadiness
{
    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    public const BLOCKED = 'BLOCKED';

    public const UNVERIFIED = 'UNVERIFIED';

    public function __construct(private readonly HealthChecks $health, private readonly AuditIntegrityVerifier $audit, private readonly TenantContext $tenants) {}

    /** @return array{checks: list<array{area: string, check: string, status: string, detail: string}>, summary: array<string, mixed>} */
    public function report(bool $verifyAudit = true): array
    {
        $checks = [];
        $add = function (string $area, string $check, string $status, string $detail = '') use (&$checks) {
            $checks[] = compact('area', 'check', 'status', 'detail');
        };
        $production = app()->environment('production');
        $level = fn (bool $ok, string $whenNot = self::FAIL) => $ok ? self::PASS : ($production ? $whenNot : self::WARN);

        // Application
        $add('application', 'APP_ENV is production', $production ? self::PASS : self::WARN, (string) app()->environment());
        $add('application', 'APP_DEBUG is off', $level(! config('app.debug')), config('app.debug') ? 'debug on' : 'debug off');
        $add('application', 'APP_KEY is set', filled(config('app.key')) ? self::PASS : self::FAIL, filled(config('app.key')) ? 'set' : 'missing');
        $add('application', 'APP_URL uses https', $level(str_starts_with((string) config('app.url'), 'https://'), self::WARN), (string) parse_url((string) config('app.url'), PHP_URL_SCHEME));
        $activeChannels = collect(array_merge([config('logging.default')], config('logging.default') === 'stack' ? (array) config('logging.channels.stack.channels', []) : []))->reject(fn ($c) => $c === 'stack')->values();
        $debugChannels = $activeChannels->filter(fn ($c) => config("logging.channels.{$c}.level", 'debug') === 'debug')->values()->all();
        $add('logging', 'Log level is not debug', $level($debugChannels === [], self::WARN), $debugChannels === [] ? 'info or above' : 'debug on: '.implode(', ', $debugChannels));
        $untapped = collect(array_merge([config('logging.default')], config('logging.default') === 'stack' ? (array) config('logging.channels.stack.channels', []) : []))
            ->filter(fn ($c) => $c !== 'stack' && ! in_array(RedactSensitiveLogData::class, (array) config("logging.channels.{$c}.tap", []), true))->values()->all();
        $add('logging', 'Every active log channel redacts sensitive data', $untapped === [] ? self::PASS : self::FAIL, $untapped === [] ? 'redaction tap on' : 'missing on: '.implode(', ', $untapped));

        // Database, cache, session, queue
        $driver = (string) config('database.connections.'.config('database.default').'.driver');
        $add('database', 'Database is MySQL (concurrency proven on MySQL)', $level($driver === 'mysql', self::WARN), $driver);
        $cache = (string) config('cache.default');
        $add('cache', 'Cache store is shared (scheduler locks, heartbeat)', in_array($cache, ['redis', 'database', 'memcached', 'dynamodb'], true) ? self::PASS : ($production ? self::FAIL : self::WARN), $cache);
        $session = (string) config('session.driver');
        $add('session', 'Session driver is shared', in_array($session, ['redis', 'database'], true) ? self::PASS : self::WARN, $session);
        $add('session', 'Session cookie is secure', $level((bool) config('session.secure'), self::WARN), config('session.secure') ? 'secure' : 'not secure');
        $queue = (string) config('queue.default');
        $add('queue', 'Queue is not sync', $queue !== 'sync' ? self::PASS : ($production ? self::FAIL : self::WARN), $queue);
        $retry = (int) config("queue.connections.{$queue}.retry_after", 0);
        $add('queue', 'retry_after exceeds the longest job timeout (3600 s)', $queue === 'sync' || $retry > 3600 ? self::PASS : self::FAIL, $queue === 'sync' ? 'sync' : "{$retry} s");

        // Storage
        $disk = (string) config('peopleos.documents.disk', 'local');
        $diskDriver = (string) config("filesystems.disks.{$disk}.driver");
        $add('storage', 'Local private disk does not serve files directly', config('filesystems.disks.local.serve') ? self::FAIL : self::PASS, config('filesystems.disks.local.serve') ? 'serve on' : 'serve off');
        $s3Disks = collect([$disk, (string) config('peopleos.enterprise.warehouse_disk', 'local')])->filter(fn ($d) => config("filesystems.disks.{$d}.driver") === 's3')->unique();
        $adapter = class_exists(AwsS3V3Adapter::class);
        $add('storage', 'S3 adapter installed for S3 disks', $s3Disks->isEmpty() ? ($adapter ? self::PASS : self::WARN) : ($adapter ? self::PASS : self::FAIL),
            $adapter ? 'league/flysystem-aws-s3-v3 present' : 'adapter not installed'.($s3Disks->isEmpty() ? ' (no S3 disk configured; needed for multi-node storage)' : ' but S3 disks configured'));
        $add('storage', 'Documents disk is private and not public', $disk !== 'public' && config("filesystems.disks.{$disk}.visibility") !== 'public' ? self::PASS : self::FAIL, "{$disk} ({$diskDriver})");

        // Mail, security, AI
        $mailer = (string) config('mail.default');
        $add('mail', 'Mailer is a real transport', in_array($mailer, ['log', 'array'], true) ? ($production ? self::FAIL : self::WARN) : self::PASS, $mailer);
        $add('mail', 'From address is configured', str_contains((string) config('mail.from.address'), 'example.com') ? self::WARN : self::PASS, str_contains((string) config('mail.from.address'), 'example.com') ? 'default example address' : 'set');
        $add('security', 'Health token is set', filled(config('peopleos.health.token')) ? self::PASS : self::WARN, filled(config('peopleos.health.token')) ? 'set' : 'not set');
        $provider = (string) config('peopleos.ai.provider', 'none');
        $add('ai', 'AI provider and data boundary', $provider === 'none' || filled(config('peopleos.ai.anthropic.key')) ? self::PASS : self::WARN, $provider === 'none' ? 'deterministic only (no external provider)' : "provider {$provider}; tenant data policy applies");

        // Production readiness closure: the configuration validator (peopleos:config:validate --as-production).
        $validator = app(ProductionConfigValidator::class)->summary(asProduction: true);
        $add('configuration', 'Production configuration validator (as production)', $validator['errors'] === 0 ? self::PASS : ($production ? self::FAIL : self::WARN),
            "{$validator['errors']} error(s), {$validator['warnings']} warning(s): run peopleos:config:validate --as-production");

        // Runtime health
        $health = $this->health->run();
        foreach ($health['checks'] as $name => $c) {
            $add('runtime', "Health: {$name}", $c['status'] === 'ok' ? self::PASS : ($c['status'] === 'fail' ? self::FAIL : self::WARN), is_array($c['detail'] ?? null) ? json_encode($c['detail']) : (string) ($c['detail'] ?? ''));
        }

        // Audit integrity
        if ($verifyAudit) {
            $broken = [];
            $checked = 0;
            $this->tenants->bypass(fn () => Tenant::query()->orderBy('id')->get())->each(function (Tenant $tenant) use (&$broken, &$checked) {
                try {
                    $result = $this->audit->verify($tenant->id);
                    $checked += $result['checked'];
                    if (! $result['valid']) {
                        $broken[] = $tenant->slug;
                    }
                } catch (Throwable) {
                    $broken[] = $tenant->slug;
                }
            });
            $platform = $this->audit->verify(null);
            $checked += $platform['checked'];
            $platform['valid'] || $broken[] = 'platform';
            $add('audit', 'Audit hash chains intact (every tenant + platform)', $broken === [] ? self::PASS : self::FAIL, $broken === [] ? "{$checked} events verified" : 'broken: '.implode(', ', $broken));
        }

        // Statutory production gate
        $statutory = $this->statutory();
        $add('statutory', 'Statutory production gate', $statutory['blocked'] ? self::BLOCKED : self::PASS,
            "{$statutory['rules']} rules / {$statutory['verified']} verified / {$statutory['open_notices']} open notices");

        // Evidence only operators can supply
        $add('operations', 'Backup restored and verified in the target environment', self::UNVERIFIED, 'operator evidence required (local restore test only; see backup-and-disaster-recovery.md)');
        $add('operations', 'Disaster recovery exercised (RPO / RTO met)', self::UNVERIFIED, 'operator evidence required');

        $fails = collect($checks)->where('status', self::FAIL)->count();

        return ['checks' => $checks, 'summary' => [
            'configuration' => $fails === 0 ? 'no blocking configuration failures' : "{$fails} failing check(s)",
            'fails' => $fails,
            'warnings' => collect($checks)->where('status', self::WARN)->count(),
            'statutory' => $statutory,
            'production_ready' => false,
            'verdict' => 'NOT DECLARED: production readiness needs every configuration check to pass, the statutory gate open (all rules verified with authoritative evidence, no open notices), a verified restore / DR exercise, and operator sign-off. Software tests alone never declare it.',
        ]];
    }

    /** @return array{rules: int, verified: int, open_notices: int, blocked: bool} */
    public function statutory(): array
    {
        if (! Schema::hasTable('compliance_rules')) {
            return ['rules' => 0, 'verified' => 0, 'open_notices' => 0, 'blocked' => true];
        }
        $rules = ComplianceRule::query()->count();
        $verified = ComplianceRule::query()->where('verification_status', ComplianceRule::VERIFIED)->count();
        $notices = ComplianceRuleNotice::query()->where('status', ComplianceRuleNotice::OPEN)->count();

        return ['rules' => $rules, 'verified' => $verified, 'open_notices' => $notices, 'blocked' => $rules === 0 || $verified < $rules || $notices > 0];
    }
}
