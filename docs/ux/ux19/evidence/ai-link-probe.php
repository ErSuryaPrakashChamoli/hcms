<?php

// UX.19 AI and reminder link probe: every assistant a showcase persona may use, asked its own example questions (and
// the Home opening question of each experience), deterministic answers only; plus every Needs Attention reminder the
// persona has (their own and, for managers, their team's). Each suggested action that survives the gateway's proposal
// filter, and each reminder link, is then opened as that persona (in-process GET through the full kernel). A link that
// the person cannot open (403/404) is a dead end. Fictional showcase data only; nothing is sent to a language model.
//
//   DB_DATABASE=<*_showcase database> php ai-link-probe.php <out.json>
use App\Domain\Ai\Services\AiGateway;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Identity\Models\User;
use App\Livewire\Experience\AiAssistant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$root = getenv('ROOT') ?: dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$out = $argv[1] ?? 'ai-link-probe.json';

$boot = function (Request $request) use ($root) {
    $app = require $root.'/bootstrap/app.php';
    $app->instance('request', $request);
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    return [$app, $kernel];
};

[$app] = $boot(Request::create('/'));
$people = User::query()->with('tenant')->where('email', 'like', '%@demo.local')->orderBy('id')->get();
$extra = array_column(AiAssistant::ROLE_PROMPTS, 1, 0);
$answers = [];
foreach ($people as $user) {
    $app['auth']->guard('web')->setUser($user);
    $app[TenantContext::class]->runAs($user->tenant, function () use ($app, $user, $extra, &$answers) {
        $gateway = $app->make(AiGateway::class);
        $assistant = (new ReflectionMethod($gateway, 'assistant'));
        $employee = Employee::query()->where('user_id', $user->id)->first();
        if ($employee !== null) {
            $attention = $app->make(NeedsAttention::class);
            $reminders = $attention->forEmployee($employee, $user);
            if ($app->make(RoleLens::class)->has($user, RoleLens::MANAGER)) {
                $reminders = $reminders->merge($attention->forManager($employee, $user));
            }
            foreach ($reminders as $r) {
                $answers[] = ['user' => $user->email, 'assistant' => 'reminder', 'question' => $r['key'].': '.$r['title'], 'links' => array_values(array_filter([$r['url'] ?? null]))];
            }
        }
        foreach (array_keys($gateway->assistantsFor($user)) as $key) {
            $questions = array_values(array_unique(array_merge($gateway->examples($key), isset($extra[$key]) ? [$extra[$key]] : [])));
            foreach ($questions as $q) {
                try {
                    $answer = $assistant->invoke($gateway, $key)->answer($user, $employee, $q);
                } catch (Throwable $e) {
                    $answers[] = ['user' => $user->email, 'assistant' => $key, 'question' => $q, 'error' => class_basename($e).': '.$e->getMessage(), 'links' => []];

                    continue;
                }
                $answers[] = ['user' => $user->email, 'assistant' => $key, 'question' => $q, 'intent' => $answer->intent,
                    'links' => array_column($gateway->proposals($answer->actions, $user), 'url')];
            }
        }
    });
}
$app['db']->disconnect();
$app->flush();

// Open every suggested link as the person it was suggested to.
$status = [];
foreach ($answers as $i => $a) {
    foreach ($a['links'] as $url) {
        $key = $a['user'].' '.$url;
        if (! isset($status[$key])) {
            $path = parse_url($url, PHP_URL_PATH).(($q = parse_url($url, PHP_URL_QUERY)) ? '?'.$q : '');
            $request = Request::create($path, 'GET');
            [$app, $kernel] = $boot($request);
            $user = User::query()->where('email', $a['user'])->firstOrFail();
            $app['auth']->guard('web')->setUser($user);
            $app['auth']->shouldUse('web');
            $res = $kernel->handle($request);
            $status[$key] = $res->getStatusCode();
            $kernel->terminate($request, $res);
            $app['db']->disconnect();
            $app->flush();
        }
        $answers[$i]['status'][$url] = $status[$key];
    }
}
$dead = array_values(array_filter(array_map(fn ($a) => array_filter($a['status'] ?? [], fn ($s) => $s >= 400) ? ['user' => $a['user'], 'assistant' => $a['assistant'], 'question' => $a['question'],
    'dead' => array_filter($a['status'], fn ($s) => $s >= 400)] : null, $answers)));
foreach ($dead as $d) {
    fprintf(STDERR, "%-30s %-16s %-45s %s\n", $d['user'], $d['assistant'], mb_strimwidth($d['question'], 0, 45), json_encode($d['dead'], JSON_UNESCAPED_SLASHES));
}
fprintf(STDERR, "answers %d, links %d, dead-end links %d\n", count($answers), array_sum(array_map(fn ($a) => count($a['links']), $answers)), array_sum(array_map(fn ($d) => count($d['dead']), $dead)));
file_put_contents($out, json_encode(['database' => getenv('DB_DATABASE'), 'answers' => $answers, 'dead' => $dead], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
