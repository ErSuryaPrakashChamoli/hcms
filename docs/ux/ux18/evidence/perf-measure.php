<?php

// UX.18 performance baseline: role surfaces per persona, in-process (a fresh application per request, the same
// kernel, middleware, tenancy, policies and scopes as a real request), WARM warm-up requests then REPS measured ones.
// Records per surface: median and p95 of wall time, queries, database time, response bytes, Livewire snapshot bytes,
// and the median server time of each Livewire component on the page (mount + render + dehydrate) — which isolates the
// phone bar (BottomNav) and the other shell components. Network and browser time are measured separately (browser).
//
//   DB_DATABASE=<*_showcase database> [ROOT=<code root>] [REPS=9] [WARM=2] [ONLY=persona:Surface] \
//     php perf-measure.php <label> <out.json>
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$root = getenv('ROOT') ?: dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
// A shared vendor/ resolves App\ to the main checkout; load this root's own app/ first (before/after worktrees).
spl_autoload_register(function (string $class) use ($root) {
    if (str_starts_with($class, 'App\\') && is_file($f = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php')) {
        require $f;
    }
}, true, true);

$label = $argv[1] ?? 'run';
$outFile = $argv[2] ?? __DIR__.'/perf-'.$label.'.json';
$reps = (int) (getenv('REPS') ?: 9);
$warm = (int) (getenv('WARM') ?: 2);
$only = getenv('ONLY') ?: null;

$surfaces = [
    'priya.nair' => ['Employee', ['Home' => '/admin', 'My work' => '/admin/my-work', 'Directory' => '/admin/people', 'My HR' => '/admin/my-hr', 'Employee 360 (own, refused)' => '/admin/employees/4', 'Notifications' => '/admin/notifications']],
    'amit.verma' => ['Manager', ['Home' => '/admin', 'My work' => '/admin/my-work', 'My team' => '/admin/my-team', 'People' => '/admin/people', 'Approval Center' => '/admin/approvals', 'Employee 360 (report)' => '/admin/employees/3', 'Notifications' => '/admin/notifications']],
    'neha.kapoor' => ['HR', ['Home' => '/admin', 'My work' => '/admin/my-work', 'People directory' => '/admin/people', 'Employee register' => '/admin/employees', 'Employee 360' => '/admin/employees/3', 'Service requests' => '/admin/tickets']],
    'meera.iyer' => ['Executive', ['Home' => '/admin', 'My work' => '/admin/my-work', 'Workforce pulse' => '/admin/workforce-command-centre']],
    'kavya.menon' => ['Administrator', ['Home' => '/admin', 'My work' => '/admin/my-work', 'Admin Centre' => '/admin/admin-centre', 'Users' => '/admin/users', 'People' => '/admin/people']],
    'arjun.bose' => ['Payroll', ['Home' => '/admin', 'My work' => '/admin/my-work', 'Payroll control room' => '/admin/payroll-control-room', 'People' => '/admin/people']],
];

$boot = function (Request $request) use ($root) {
    $app = require $root.'/bootstrap/app.php';
    $app->instance('request', $request);
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    // Livewire reports per-component mount/render/dehydrate time when debugging (same setting before and after).
    config(['app.debug' => true]);

    return [$app, $kernel];
};
$pct = function (array $values, float $p): float {
    sort($values);

    return (float) $values[max(0, (int) ceil($p * count($values)) - 1)];
};

$out = ['label' => $label, 'database' => getenv('DB_DATABASE'), 'reps' => $reps, 'warm' => $warm, 'rows' => []];
foreach ($surfaces as $who => [$role, $screens]) {
    foreach ($screens as $name => $path) {
        if ($only !== null && $only !== $who.':'.$name) {
            continue;
        }
        $samples = [];
        for ($pass = 1; $pass <= $warm + $reps; $pass++) {
            $request = Request::create($path, 'GET');
            [$app, $kernel] = $boot($request);
            $user = User::query()->where('email', $who.'@demo.local')->firstOrFail();
            $app['auth']->guard('web')->setUser($user);
            $app['auth']->shouldUse('web');
            $q = 0;
            $dbms = 0.0;
            DB::listen(function ($e) use (&$q, &$dbms) {
                $q++;
                $dbms += $e->time;
            });
            $classes = [];
            $components = [];
            \Livewire\on('mount', function ($component) use (&$classes) {
                $classes[$component->getId()] = class_basename($component);
            });
            \Livewire\on('profile', function ($type, $id, $times) use (&$classes, &$components) {
                $name = $classes[$id] ?? $id;
                $components[$name] = ($components[$name] ?? 0) + ($times[1] - $times[0]) * 1000;
            });
            $t = hrtime(true);
            $res = $kernel->handle($request);
            $ms = (hrtime(true) - $t) / 1e6;
            $kernel->terminate($request, $res);
            $html = (string) $res->getContent();
            preg_match_all('/wire:snapshot="([^"]*)"/', $html, $snaps);
            if ($pass > $warm) {
                $samples[] = ['status' => $res->getStatusCode(), 'queries' => $q, 'db_ms' => $dbms, 'ms' => $ms, 'bytes' => strlen($html),
                    'snapshot_bytes' => array_sum(array_map('strlen', $snaps[1])), 'components' => $components];
            }
            // Close this pass's connection (a fresh application per pass would otherwise pile up MySQL connections).
            $app['db']->disconnect();
            $app->flush();
        }
        $col = fn (string $k) => array_column($samples, $k);
        $componentNames = array_unique(array_merge(...array_map(fn ($s) => array_keys($s['components']), $samples)));
        $byComponent = [];
        foreach ($componentNames as $c) {
            $byComponent[$c] = round($pct(array_map(fn ($s) => $s['components'][$c] ?? 0, $samples), 0.5), 1);
        }
        arsort($byComponent);
        $row = ['viewer' => $who, 'role' => $role, 'surface' => $name, 'path' => $path, 'status' => $samples[0]['status'],
            'ms_median' => round($pct($col('ms'), 0.5)), 'ms_p95' => round($pct($col('ms'), 0.95)),
            'queries' => (int) $pct($col('queries'), 0.5), 'db_ms_median' => round($pct($col('db_ms'), 0.5)), 'db_ms_p95' => round($pct($col('db_ms'), 0.95)),
            'bytes' => (int) $pct($col('bytes'), 0.5), 'snapshot_bytes' => (int) $pct($col('snapshot_bytes'), 0.5), 'components_ms' => $byComponent];
        $out['rows'][] = $row;
        fprintf(STDERR, "%-12s %-13s %-28s %d med=%5dms p95=%5dms q=%4d db=%5dms bar=%5.1fms\n", $who, $role, $name, $row['status'], $row['ms_median'], $row['ms_p95'], $row['queries'], $row['db_ms_median'], $byComponent['BottomNav'] ?? 0);
    }
}
file_put_contents($outFile, json_encode($out, JSON_PRETTY_PRINT));
