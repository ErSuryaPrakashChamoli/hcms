<?php

// UX.15 closure P1-02: approval-heavy screens at 10,785 employees, in-process (fresh app per request), median of 3.
// ROOT=<code root> DB_DATABASE=hcm_ux_scale_showcase php approval-measure.php <label>
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
$boot = function (?Request $request = null) use ($root) {
    $app = require $root.'/bootstrap/app.php';
    $app->instance('request', $request ?? Request::create('/'));
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    return [$app, $kernel];
};
$cases = [['amit.verma', 'Approvals', '/admin/approvals'], ['amit.verma', 'Home', '/admin'], ['amit.verma', 'My Work', '/admin/my-work'], ['neha.kapoor', 'Approvals', '/admin/approvals']];
$out = [];
foreach ($cases as [$who, $name, $path]) {
    $runs = [];
    foreach (range(1, 4) as $pass) {
        $request = Request::create($path, 'GET');
        [$app, $kernel] = $boot($request);
        $user = User::query()->where('email', $who.'@demo.local')->firstOrFail();
        $app['auth']->guard('web')->setUser($user);
        $app['auth']->shouldUse('web');
        $q = 0;
        $dbms = 0.0;
        $authz = 0;
        DB::listen(function ($e) use (&$q, &$dbms, &$authz) {
            $q++;
            $dbms += $e->time;
            if (preg_match('/^select exists\(select .* from `employees`/', $e->sql)) {
                $authz++;
            }
        });
        $t = hrtime(true);
        $res = $kernel->handle($request);
        $ms = (hrtime(true) - $t) / 1e6;
        $kernel->terminate($request, $res);
        if ($pass > 1) {
            $runs[] = ['status' => $res->getStatusCode(), 'queries' => $q, 'authz_exists' => $authz, 'db_ms' => round($dbms), 'ms' => round($ms), 'heading' => preg_match('/<h1[^>]*>\s*([^<]+?)\s*<\/h1>/', (string) $res->getContent(), $m) ? $m[1] : null];
        }
        $app->flush();
    }
    usort($runs, fn ($a, $b) => $a['ms'] <=> $b['ms']);
    $med = $runs[1];
    $out[] = ['label' => $label, 'viewer' => $who, 'screen' => $name] + $med;
    fprintf(STDERR, "%-12s %-10s %d q=%4d authz_exists=%3d db=%5dms wall=%5dms  %s\n", $who, $name, $med['status'], $med['queries'], $med['authz_exists'], $med['db_ms'], $med['ms'], $med['heading']);
}
file_put_contents(__DIR__.'/approval-'.$label.'.json', json_encode($out, JSON_PRETTY_PRINT));
