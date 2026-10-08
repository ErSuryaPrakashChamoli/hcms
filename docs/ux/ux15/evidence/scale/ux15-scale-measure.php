<?php

// UX.15.20: in-process measurement of the experience surfaces (fresh app per request; query count, DB ms,
// wall ms, HTML bytes) against DB_DATABASE. Usage: DB_DATABASE=hcm_ux_scale_showcase php scale-measure.php <label> [filter]
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$root = '/home/administrator/Documents/hrms/hcms';
require $root.'/vendor/autoload.php';
$label = $argv[1] ?? 'run';
$filter = $argv[2] ?? null;

$boot = function (?Request $request = null) use ($root) {
    $app = require $root.'/bootstrap/app.php';
    $app->instance('request', $request ?? Request::create('/'));
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    return [$app, $kernel];
};
[$app] = $boot();
$tenants = $app->make(TenantContext::class);
$tenant = $tenants->bypass(fn () => Tenant::query()->where('slug', 'demo')->first());
$ids = $tenants->runAs($tenant, fn () => [
    'priya' => Employee::query()->where('work_email', 'priya.nair@demo.local')->value('id'),
    'amit' => Employee::query()->where('work_email', 'amit.verma@demo.local')->value('id'),
    'long' => Employee::query()->where('employee_code', 'UXE000001')->value('id'),
    'count' => Employee::query()->count(),
]);

$cases = [
    ['priya', 'Home', '/admin'], ['amit', 'Home', '/admin'], ['neha', 'Home', '/admin'], ['meera', 'Home', '/admin'],
    ['amit', 'My Work', '/admin/my-work'], ['neha', 'My Work', '/admin/my-work'],
    ['amit', 'Approvals', '/admin/approvals'], ['neha', 'Approvals', '/admin/approvals'],
    ['neha', 'People', '/admin/people'], ['amit', 'People', '/admin/people'],
    ['neha', 'Employee 360 (Priya)', '/admin/employees/'.$ids['priya']], ['neha', 'Employee 360 (Amit)', '/admin/employees/'.$ids['amit']],
    ['amit', 'Employee 360 (Priya)', '/admin/employees/'.$ids['priya']],
    ['neha', 'Org map', '/admin/organisation-map'], ['amit', 'Org map', '/admin/organisation-map'],
    ['meera', 'Workforce pulse', '/admin/workforce-command-centre'], ['kavya', 'Workforce pulse', '/admin/workforce-command-centre'],
    ['priya', 'My HR', '/admin/my-hr'], ['kavya', 'Admin centre', '/admin/admin-centre'], ['amit', 'Notifications', '/admin/notifications'],
];
if ($ids['long']) {
    $cases[] = ['neha', 'Employee 360 (long name)', '/admin/employees/'.$ids['long']];
}
$out = [];
foreach ($cases as [$who, $name, $path]) {
    if ($filter && ! str_contains(strtolower($name), strtolower($filter))) {
        continue;
    }
    foreach ([1, 2] as $pass) { // pass 1 warms caches (views, config); pass 2 is reported
        $request = Request::create($path, 'GET');
        [$app, $kernel] = $boot($request);
        $user = User::query()->where('email', $who.'.'.['priya' => 'nair', 'amit' => 'verma', 'neha' => 'kapoor', 'meera' => 'iyer', 'kavya' => 'menon'][$who].'@demo.local')->firstOrFail();
        $app['auth']->guard('web')->setUser($user);
        $app['auth']->shouldUse('web');
        $q = 0;
        $dbms = 0.0;
        $slow = [];
        $shapes = [];
        DB::listen(function ($e) use (&$q, &$dbms, &$slow, &$shapes) {
            $q++;
            $k = preg_replace('/\(\?(, \?)*\)/', '(…)', $e->sql);
            $shapes[$k] = ($shapes[$k] ?? 0) + 1;
            if (($tp = getenv('TRACE')) && str_contains($e->sql, $tp)) {
                $fr = array_values(array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80), fn ($f) => isset($f['file']) && str_contains($f['file'], '/app/')));
                $c = implode(' < ', array_map(fn ($f) => basename($f['file']).':'.$f['line'], array_slice($fr, 0, (int) (getenv('FRAMES') ?: 3))));
                if (getenv('KEYS')) {
                    $c .= ' '.json_encode($e->bindings);
                } $GLOBALS['traces'][$c] = ($GLOBALS['traces'][$c] ?? 0) + 1;
            } $dbms += $e->time;
            if ($e->time > 50) {
                $slow[] = round($e->time).'ms '.substr($e->sql, 0, 160);
            }
        });
        $t = hrtime(true);
        $res = $kernel->handle($request);
        $ms = (hrtime(true) - $t) / 1e6;
        $kernel->terminate($request, $res);
        if ($pass === 2) {
            $row = ['label' => $label, 'employees' => $ids['count'], 'viewer' => $who, 'screen' => $name, 'status' => $res->getStatusCode(), 'queries' => $q, 'db_ms' => round($dbms), 'ms' => round($ms), 'kb' => round(strlen((string) $res->getContent()) / 1024), 'slow' => array_slice($slow, 0, 3)];
            $out[] = $row;
            fprintf(STDERR, "%-6s %-26s %3d q=%4d db=%5dms wall=%5dms %4dKB %s\n", $who, $name, $row['status'], $q, $row['db_ms'], $row['ms'], $row['kb'], $slow ? ' SLOW: '.implode(' | ', $row['slow']) : '');
            if (getenv('DUMP')) {
                arsort($shapes);
                foreach (array_slice($shapes, 0, (int) getenv('DUMP'), true) as $k => $c) {
                    if ($c > 1) {
                        fprintf(STDERR, "   %4d× %s\n", $c, substr($k, 0, 230));
                    }
                }
            }
            if (! empty($GLOBALS['traces'])) {
                arsort($GLOBALS['traces']);
                foreach (array_slice($GLOBALS['traces'], 0, 15, true) as $c => $n) {
                    fprintf(STDERR, "   T %4d× %s\n", $n, substr($c, 0, 300));
                } $GLOBALS['traces'] = [];
            }
            if ($res->getStatusCode() >= 400 || $res->getStatusCode() === 302) {
                fprintf(STDERR, "   -> %s\n", substr(strip_tags((string) $res->getContent()), 0, 300));
            }
        }
        $app->flush();
    }
}
file_put_contents(__DIR__.'/scale/'.$label.'.json', json_encode($out, JSON_PRETTY_PRINT));
