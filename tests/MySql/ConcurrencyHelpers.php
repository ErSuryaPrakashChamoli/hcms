<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 | Shared fork-based race harness for the MySQL concurrency suites (Phase 8 learning, Phase 9 talent).
 */

/**
 * Run each callback in its own forked process, all starting together. Each child opens its own
 * MySQL connection; results come back through temp files ('ok' or the exception message). The
 * model events in $slow pause for $pause seconds, widening the window between reading state and
 * writing it, so a missing row lock would let both writers through.
 *
 * @param  list<callable>  $callbacks
 * @param  list<string>  $slow  e.g. 'eloquent.creating: '.Model::class
 * @return list<string>
 */
function race(array $callbacks, float $pause = 0.4, array $slow = []): array
{
    DB::disconnect();
    $start = microtime(true) + 0.5;
    $files = [];
    $pids = [];
    foreach ($callbacks as $i => $callback) {
        $files[$i] = tempnam(sys_get_temp_dir(), 'race');
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge();
            // Widen the window between reading state and writing it: without a row lock, both writers pass.
            foreach ($slow as $event) {
                Event::listen($event, fn () => usleep((int) ($pause * 1_000_000)));
            }
            while (microtime(true) < $start) {
                usleep(1000);
            }
            try {
                $callback();
                file_put_contents($files[$i], 'ok');
            } catch (Throwable $e) {
                file_put_contents($files[$i], $e->getMessage());
            }
            posix_kill(getmypid(), SIGKILL); // skip shutdown handlers inherited from the test runner
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    DB::purge();

    return array_map(function ($file) {
        $result = (string) file_get_contents($file);
        unlink($file);

        return $result;
    }, $files);
}

/**
 * Switch this test to the shared database cache store, so cache invalidation and cache gates cross the forked
 * processes the way they cross real ones (2026-11-25). The default store was resolved while the application booted, on
 * the array store from phpunit.xml: services that inject the cache repository (EntitlementStateStore, ShadowRecorder)
 * kept that process-local array, so each forked process had a private cache and the cross-process tests could not see
 * each other's invalidations. Changing cache.default alone did not reach them.
 */
function useSharedDatabaseCache(string $connection = 'concurrency'): void
{
    config(['cache.default' => 'database', 'cache.stores.database.connection' => $connection]);
    app('cache')->forgetDriver(['array', 'database']);
    app()->forgetInstance('cache.store');
    Cache::clearResolvedInstances();
    app()->forgetScopedInstances();
}
