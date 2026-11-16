<?php

// Read-only discovery for the Organisation Administrator training.
// Run with the training environment: source tools/env.sh && php docs/training/organisation-admin/tools/discover.php
// Signs in (in-process) as the organisation administrator of the demo tenant and records what the application itself
// grants that user: the rail navigation (ExperienceNavigation), the module catalogue (ModuleCatalogue) and every
// Filament resource and page with its access verdict. Writes tools/.work/discovery.json. Changes nothing.

use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\ModuleCatalogue;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'hcm_orgadmin_showcase') {
    fwrite(STDERR, 'Refusing: not the training database ('.DB::connection()->getDatabaseName().'). Source tools/env.sh first.'.PHP_EOL);
    exit(1);
}

$email = $argv[1] ?? 'admin@demo.local';
$tenants = app(TenantContext::class);
$tenant = $tenants->bypass(fn () => Tenant::query()->where('slug', 'demo')->firstOrFail());
$out = $tenants->runAs($tenant, function () use ($email, $tenant) {
    $user = User::query()->where('email', $email)->firstOrFail();
    Auth::guard('web')->setUser($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $panel = Filament::getPanel('admin');
    $path = fn (?string $url) => $url === null ? null : (parse_url($url, PHP_URL_PATH) ?: '/');

    $roles = $user->roles()->get(['roles.slug', 'roles.name'])->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name])->all();

    $nav = app(ExperienceNavigation::class);
    $sections = [];
    foreach (ExperienceNavigation::ORDER as $key) {
        $children = $nav->children($user, $key);
        $landing = $nav->landing($user, $key);
        if ($landing === null && $children === []) {
            continue;
        }
        $sections[] = [
            'key' => $key, 'label' => ExperienceNavigation::SECTIONS[$key]['label'], 'landing' => $path($landing['url'] ?? null),
            'areas' => array_map(fn ($a) => ['label' => $a['label'], 'url' => $path($a['url']), 'modules' => array_map(fn ($m) => [
                'class' => $m['key'], 'label' => $m['label'], 'group' => $m['group'], 'space' => $m['space'], 'url' => $path($m['url']), 'kind' => $m['kind'],
            ], $a['modules'])], $children),
        ];
    }

    $catalogue = array_map(fn ($m) => ['class' => $m['key'], 'label' => $m['label'], 'group' => $m['group'], 'space' => $m['space'], 'url' => $path($m['url']), 'kind' => $m['kind']],
        app(ModuleCatalogue::class)->for($user));

    $resources = [];
    foreach ($panel->getResources() as $resource) {
        $row = ['class' => $resource, 'label' => null, 'group' => null, 'nav' => null, 'access' => null, 'pages' => [], 'model' => null, 'records' => null, 'error' => null];
        try {
            $row['label'] = $resource::getNavigationLabel();
            $row['group'] = $resource::getNavigationGroup() instanceof BackedEnum ? $resource::getNavigationGroup()->value : (string) $resource::getNavigationGroup();
            $row['nav'] = $resource::shouldRegisterNavigation();
            $row['access'] = $resource::canAccess();
            $row['model'] = $resource::getModel();
            $row['records'] = $row['access'] ? $resource::getEloquentQuery()->count() : null;
            foreach ($resource::getPages() as $name => $page) {
                $row['pages'][$name] = ['route' => $resource::getRouteBaseName($panel).'.'.$name];
            }
            if ($row['access'] && $row['records']) {
                $first = $resource::getEloquentQuery()->orderBy($resource::getModel()::make()->getKeyName())->first();
                if ($first) {
                    foreach (['view', 'edit'] as $p) {
                        if (isset($row['pages'][$p])) {
                            try { $row['pages'][$p]['sample'] = $path($resource::getUrl($p, ['record' => $first])); } catch (Throwable) {}
                        }
                    }
                }
            }
            if (isset($row['pages']['index'])) { try { $row['pages']['index']['url'] = $path($resource::getUrl('index')); } catch (Throwable) {} }
            if (isset($row['pages']['create'])) { try { $row['pages']['create']['url'] = $path($resource::getUrl('create')); $row['can_create'] = $resource::canCreate(); } catch (Throwable) {} }
        } catch (Throwable $e) {
            $row['error'] = class_basename($e).': '.mb_substr($e->getMessage(), 0, 200);
        }
        $resources[] = $row;
    }

    $pages = [];
    foreach ($panel->getPages() as $page) {
        $row = ['class' => $page, 'label' => null, 'group' => null, 'nav' => null, 'access' => null, 'url' => null, 'error' => null];
        try {
            $row['label'] = $page::getNavigationLabel();
            $row['group'] = $page::getNavigationGroup() instanceof BackedEnum ? $page::getNavigationGroup()->value : (string) $page::getNavigationGroup();
            $row['nav'] = $page::shouldRegisterNavigation();
            $row['access'] = $page::canAccess();
            $row['url'] = $path($page::getUrl());
        } catch (Throwable $e) {
            $row['error'] = class_basename($e).': '.mb_substr($e->getMessage(), 0, 200);
        }
        $pages[] = $row;
    }

    return ['user' => $email, 'tenant' => $tenant->name, 'roles' => $roles, 'permissions' => $user->permissionKeys()->values()->all(),
        'sections' => $sections, 'catalogue' => $catalogue, 'resources' => $resources, 'pages' => $pages, 'generated_at' => now()->toIso8601String()];
});

$file = __DIR__.'/.work/discovery'.($email === 'admin@demo.local' ? '' : '-'.preg_replace('/\W+/', '_', $email)).'.json';
file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$acc = fn ($l) => count(array_filter($l, fn ($r) => $r['access']));
printf("%s (%s): %d sections, %d catalogue modules, resources %d/%d accessible, pages %d/%d accessible -> %s\n",
    $email, implode(', ', array_column($out['roles'], 'name')), count($out['sections']), count($out['catalogue']),
    $acc($out['resources']), count($out['resources']), $acc($out['pages']), count($out['pages']), $file);
