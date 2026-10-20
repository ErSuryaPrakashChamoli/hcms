<?php

// UX.18 query profile of one surface: every SQL statement with its time and the first application frame that issued
// it, grouped by caller, plus exact duplicates (same SQL and bindings). One warm-up request, then the profiled one.
//
//   DB_DATABASE=<*_showcase database> [ROOT=<code root>] php perf-profile.php <persona> <path> [out.json]
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$root = getenv('ROOT') ?: dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
spl_autoload_register(function (string $class) use ($root) {
    if (str_starts_with($class, 'App\\') && is_file($f = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php')) {
        require $f;
    }
}, true, true);

[$script, $who, $path] = $argv + [null, 'neha.kapoor', '/admin'];
$outFile = $argv[3] ?? null;
$rows = [];
foreach ([1, 2] as $pass) {
    $request = Request::create($path, 'GET');
    $app = require $root.'/bootstrap/app.php';
    $app->instance('request', $request);
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $user = User::query()->where('email', $who.'@demo.local')->firstOrFail();
    $app['auth']->guard('web')->setUser($user);
    $app['auth']->shouldUse('web');
    $rows = [];
    DB::listen(function ($e) use (&$rows, $root) {
        $frame = null;
        $chain = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $f) {
            $file = $f['file'] ?? '';
            if ($file !== '' && str_starts_with($file, $root.'/app/')) {
                $where = substr($file, strlen($root) + 1).':'.($f['line'] ?? 0);
                $frame ??= $where;
                if (count($chain) < 4) {
                    $chain[] = $where;
                }
            } elseif ($file !== '' && str_contains($file, '/storage/framework/views/')) {
                $frame ??= 'view:'.basename($file);
            }
        }
        $rows[] = ['ms' => $e->time, 'sql' => $e->sql, 'bindings' => array_map(fn ($b) => is_object($b) ? (string) (method_exists($b, 'format') ? $b->format('c') : get_class($b)) : $b, $e->bindings), 'caller' => $frame ?? 'framework', 'chain' => $chain];
    });
    $t = hrtime(true);
    $res = $kernel->handle($request);
    $wall = (hrtime(true) - $t) / 1e6;
    $kernel->terminate($request, $res);
    $app['db']->disconnect();
    $app->flush();
}
$byCaller = [];
foreach ($rows as $r) {
    $c = $r['caller'];
    $byCaller[$c] ??= ['caller' => $c, 'queries' => 0, 'ms' => 0.0, 'chain' => $r['chain']];
    $byCaller[$c]['queries']++;
    $byCaller[$c]['ms'] += $r['ms'];
}
usort($byCaller, fn ($a, $b) => $b['ms'] <=> $a['ms']);
$dups = [];
foreach ($rows as $r) {
    $k = $r['sql'].'|'.json_encode($r['bindings']);
    $dups[$k] ??= ['sql' => mb_substr($r['sql'], 0, 220), 'count' => 0, 'ms' => 0.0, 'callers' => []];
    $dups[$k]['count']++;
    $dups[$k]['ms'] += $r['ms'];
    $dups[$k]['callers'][$r['caller']] = true;
}
$dups = array_values(array_filter($dups, fn ($d) => $d['count'] > 1));
usort($dups, fn ($a, $b) => $b['ms'] <=> $a['ms']);
$slow = $rows;
usort($slow, fn ($a, $b) => $b['ms'] <=> $a['ms']);
$summary = ['persona' => $who, 'path' => $path, 'status' => $res->getStatusCode(), 'wall_ms' => round($wall), 'queries' => count($rows), 'db_ms' => round(array_sum(array_column($rows, 'ms'))),
    'by_caller' => array_map(fn ($c) => ['caller' => $c['caller'], 'queries' => $c['queries'], 'ms' => round($c['ms'], 1), 'chain' => $c['chain']], array_slice($byCaller, 0, 40)),
    'duplicates' => array_map(fn ($d) => ['count' => $d['count'], 'ms' => round($d['ms'], 1), 'sql' => $d['sql'], 'callers' => array_keys($d['callers'])], array_slice($dups, 0, 30)),
    'slowest' => array_map(fn ($r) => ['ms' => round($r['ms'], 1), 'caller' => $r['caller'], 'sql' => mb_substr($r['sql'], 0, 300)], array_slice($slow, 0, 15))];
fprintf(STDERR, "%s %s status=%d wall=%dms queries=%d db=%dms duplicates=%d\n", $who, $path, $summary['status'], $summary['wall_ms'], $summary['queries'], $summary['db_ms'], array_sum(array_map(fn ($d) => $d['count'] - 1, $dups)));
foreach (array_slice($summary['by_caller'], 0, 14) as $c) {
    fprintf(STDERR, "  %4d q %7.1f ms  %s\n", $c['queries'], $c['ms'], $c['caller']);
}
if ($outFile) {
    file_put_contents($outFile, json_encode($summary, JSON_PRETTY_PRINT));
}
