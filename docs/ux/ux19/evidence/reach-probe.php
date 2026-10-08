<?php

// UX.19 reach probe: can each showcase persona open the destinations that reminders, AI suggestions and the
// Employee 360 point to? In-process GET through the full kernel (middleware, tenancy, policies, scopes), signed in as
// the persona. Records the HTTP status per persona and path. Fictional showcase data only.
// With ALL=1 it sweeps every parameterless GET page of the admin panel instead (a 5xx or 404 there is a defect; a 403
// is a correct refusal).
//
// PERSONA=<e-mail> limits a run to one persona (the ALL sweep runs one process per persona: building a fresh
// application per request accumulates memory in one process).
//
//   DB_DATABASE=<*_showcase database> [ALL=1] [PERSONA=<e-mail>] php reach-probe.php <out.json>
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$root = getenv('ROOT') ?: dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';

$out = $argv[1] ?? 'reach-probe.json';
$paths = array_values(array_unique(array_merge(
    // NeedsAttention reminder destinations (hard-coded slugs before UX.19).
    ['/admin/kb', '/admin/announcements', '/admin/learning-enrolments', '/admin/appraisals', '/admin/tickets', '/admin/assets', '/admin/task-inbox',
        '/admin/tax-declarations', '/admin/leave-requests', '/admin/attendance-regularisations', '/admin/grievances', '/admin/one-on-ones'],
    // AI suggested-action destinations (hard-coded in the assistants).
    ['/admin/articles', '/admin/bgv-cases', '/admin/career-paths', '/admin/change-intelligence', '/admin/employees', '/admin/exit-cases', '/admin/goals',
        '/admin/leave-balances', '/admin/my-day', '/admin/my-team', '/admin/my-work', '/admin/onboarding-plans', '/admin/payroll-auditor',
        '/admin/payroll-control-room', '/admin/people-analytics', '/admin/reports', '/admin/workforce-command-centre'],
    // Self-service alternatives.
    ['/admin/my-hr', '/admin/my-hr?tab=policies', '/admin/announcements-feed', '/admin/my-learning', '/admin/my-career', '/admin/my-compensation', '/admin/team-learning'],
)));

$boot = function (Request $request) use ($root) {
    $app = require $root.'/bootstrap/app.php';
    $app->instance('request', $request);
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    return [$app, $kernel];
};

[$app] = $boot(Request::create('/'));
$people = getenv('PERSONA') ? [getenv('PERSONA')] : User::query()->where('email', 'like', '%@demo.local')->orderBy('id')->pluck('email')->all();
if (getenv('ALL')) {
    $paths = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods(), true) && str_starts_with($r->uri(), 'admin') && ! str_contains($r->uri(), '{')
            && ! preg_match('#^admin/(login|logout|password|register|email|two-factor|multi-factor|tenancy)#', $r->uri()))
        ->map(fn ($r) => '/'.$r->uri())->unique()->sort()->values()->all();
}
$app['db']->disconnect();
$app->flush();

$rows = [];
foreach ($people as $email) {
    $own = null;
    foreach (array_merge($paths, ['own-360']) as $path) {
        $request = Request::create('/');
        [$app, $kernel] = $boot($request);
        $user = User::query()->where('email', $email)->firstOrFail();
        if ($path === 'own-360') {
            $own = Employee::query()->withoutGlobalScopes()->where('user_id', $user->id)->value('id');
            if ($own === null) {
                $app['db']->disconnect();
                $app->flush();

                continue;
            }
            $path = '/admin/employees/'.$own;
        }
        $request = Request::create($path, 'GET');
        $app->instance('request', $request);
        $app['auth']->guard('web')->setUser($user);
        $app['auth']->shouldUse('web');
        $res = $kernel->handle($request);
        $status = $res->getStatusCode();
        $kernel->terminate($request, $res);
        $rows[$email][$path] = $status;
        $app['db']->disconnect();
        $app->flush();
    }
    $notable = array_filter($rows[$email], fn ($s) => $s !== 200 && ! (getenv('ALL') && $s === 403));
    fprintf(STDERR, "%-34s %s\n", $email, implode(' ', array_map(fn ($p, $s) => $p.'='.$s, array_keys($notable), $notable)));
}
file_put_contents($out, json_encode(['database' => getenv('DB_DATABASE'), 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
