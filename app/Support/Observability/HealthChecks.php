<?php

namespace App\Support\Observability;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 14 readiness checks (used by /health/ready and peopleos:readiness). Each check returns
 * status ok | warn | fail, whether it is critical, and a safe detail (counts, ages, driver names;
 * never credentials, hosts or tenant data).
 */
final class HealthChecks
{
    public const HEARTBEAT_KEY = 'peopleos:scheduler:heartbeat';

    /** @return array{status: string, checks: array<string, array{status: string, critical: bool, detail?: mixed, ms?: float}>} */
    public function run(): array
    {
        $checks = [
            'database' => $this->timed(true, fn () => DB::select('select 1') ? ['status' => 'ok', 'detail' => DB::connection()->getDriverName()] : ['status' => 'fail']),
            'cache' => $this->timed(true, function () {
                $key = 'peopleos:health:'.Str::random(8);
                Cache::put($key, 'ok', 30);
                $ok = Cache::get($key) === 'ok';
                Cache::forget($key);

                return ['status' => $ok ? 'ok' : 'fail', 'detail' => config('cache.default')];
            }),
            'storage' => $this->timed(true, function () {
                $disk = Storage::disk(config('peopleos.documents.disk', 'local'));
                $path = 'health/'.Str::ulid().'.txt';
                $disk->put($path, 'ok');
                $ok = $disk->get($path) === 'ok';
                $disk->delete($path);

                return ['status' => $ok ? 'ok' : 'fail', 'detail' => config('filesystems.disks.'.config('peopleos.documents.disk', 'local').'.driver')];
            }),
            'queue' => $this->timed(false, function () {
                $connection = config('queue.default');
                $size = $connection === 'sync' ? 0 : Queue::connection($connection)->size();
                $max = (int) config('peopleos.health.queue_backlog_warn', 1000);

                return ['status' => $connection === 'sync' && app()->isProduction() ? 'warn' : ($size > $max ? 'warn' : 'ok'), 'detail' => ['connection' => $connection, 'pending' => $size]];
            }),
            'failed_jobs' => $this->timed(false, function () {
                $table = config('queue.failed.table', 'failed_jobs');
                $count = Schema::hasTable($table) ? DB::table($table)->count() : 0;

                return ['status' => $count > 0 ? 'warn' : 'ok', 'detail' => ['failed' => $count]];
            }),
            'scheduler' => $this->timed(false, function () {
                $beat = Cache::get(self::HEARTBEAT_KEY);
                $age = $beat ? now()->getTimestamp() - (int) $beat : null;

                return ['status' => $age !== null && $age <= (int) config('peopleos.health.heartbeat_max_age', 180) ? 'ok' : 'warn', 'detail' => ['last_beat_seconds_ago' => $age]];
            }),
            'integrations' => $this->timed(false, function () {
                $counts = app(TenantContext::class)->bypass(fn () => [
                    'inbound_dead_letter' => Schema::hasTable('inbound_events') ? DB::table('inbound_events')->where('status', 'dead_letter')->count() : 0,
                    'webhook_dead_letter' => Schema::hasTable('webhook_deliveries') ? DB::table('webhook_deliveries')->where('status', 'dead_letter')->count() : 0,
                ]);

                return ['status' => array_sum($counts) > 0 ? 'warn' : 'ok', 'detail' => $counts];
            }),
        ];

        if (in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true)) {
            $checks['redis'] = $this->timed(true, fn () => ['status' => Redis::connection()->ping() ? 'ok' : 'fail']);
        }

        $status = collect($checks)->contains(fn ($c) => $c['critical'] && $c['status'] === 'fail') ? 'down'
            : (collect($checks)->contains(fn ($c) => $c['status'] !== 'ok') ? 'degraded' : 'ok');

        return compact('status', 'checks');
    }

    /** @return array{status: string, critical: bool, detail?: mixed, ms: float} */
    private function timed(bool $critical, callable $check): array
    {
        $started = hrtime(true);
        try {
            $result = $check();
        } catch (Throwable $e) {
            $result = ['status' => 'fail', 'detail' => class_basename($e)];
        }

        return ['critical' => $critical, ...$result, 'ms' => round((hrtime(true) - $started) / 1e6, 1)];
    }
}
