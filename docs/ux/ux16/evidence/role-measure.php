<?php

// UX.16.27: role-aware screens per persona, in-process (fresh app per request), median of 3 after one warm-up.
// ROOT=<code root> DB_DATABASE=<showcase database> php role-measure.php <label> [out.json]
// Records queries, database and wall time, response bytes and the Livewire snapshot bytes (payload proxy).
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$root = getenv('ROOT') ?: '/home/administrator/Documents/hrms/hcms';
require $root.'/vendor/autoload.php';
// The shared vendor/ resolves App\ to the real repository; load this root's own app/ first.
spl_autoload_register(function (string $class) use ($root) {
    if (str_starts_with($class, 'App\\') && is_file($f = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php')) {
        require $f;
    }
}, true, true);
$label = $argv[1] ?? 'run';
$outFile = $argv[2] ?? __DIR__.'/roles-'.$label.'.json';
$boot = function (?Request $request = null) use ($root) {
    $app = require $root.'/bootstrap/app.php';
    $app->instance('request', $request ?? Request::create('/'));
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    return [$app, $kernel];
};
$personas = ['priya.nair' => 'Employee', 'amit.verma' => 'Manager', 'neha.kapoor' => 'HR', 'arjun.bose' => 'Payroll', 'meera.iyer' => 'Executive', 'kavya.menon' => 'Administrator'];
$screens = ['Home' => '/admin', 'My work' => '/admin/my-work', 'People' => '/admin/people'];
$out = [];
$only = getenv('ONLY') ?: null; // optional "viewer:Screen" to repeat one case
foreach ($personas as $who => $role) {
    foreach ($screens as $name => $path) {
        if ($only !== null && $only !== $who.':'.$name) {
            continue;
        }
        $runs = [];
        foreach (range(1, 4) as $pass) {
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
            $t = hrtime(true);
            $res = $kernel->handle($request);
            $ms = (hrtime(true) - $t) / 1e6;
            $kernel->terminate($request, $res);
            $html = (string) $res->getContent();
            preg_match_all('/wire:snapshot="([^"]*)"/', $html, $snaps);
            if ($pass > 1) {
                $runs[] = ['status' => $res->getStatusCode(), 'queries' => $q, 'db_ms' => round($dbms), 'ms' => round($ms), 'bytes' => strlen($html), 'snapshot_bytes' => array_sum(array_map('strlen', $snaps[1]))];
            }
            $app->flush();
        }
        usort($runs, fn ($a, $b) => $a['ms'] <=> $b['ms']);
        $med = $runs[1];
        $out[] = ['label' => $label, 'viewer' => $who, 'role' => $role, 'screen' => $name] + $med;
        fprintf(STDERR, "%-12s %-13s %-8s %d q=%4d db=%5dms wall=%5dms bytes=%7d snapshot=%6d\n", $who, $role, $name, $med['status'], $med['queries'], $med['db_ms'], $med['ms'], $med['bytes'], $med['snapshot_bytes']);
    }
}
file_put_contents($outFile, json_encode($out, JSON_PRETTY_PRINT));
