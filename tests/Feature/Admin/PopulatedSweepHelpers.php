<?php

use App\Domain\Identity\Models\User;
use Database\Seeders\Demo\DemoOrganisation;
use Database\Seeders\Demo\DemoOrganisationSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Shared by the QA sweeps: the fictional demo organisation (every module filled through its domain services by
| DemoOrganisationSeeder) and the list of every screen of the panel, so screens are opened with real records in them
| rather than in an empty tenant — and so each persona is also sent to the screens and records it must not reach.
*/

/** Seeds the demo organisation, binds its tenant and returns its tenant super admin. */
function seedPopulatedOrganisation(): User
{
    config(['peopleos.demo.password' => 'Populated-Sweep-2026']);
    test()->seed(DemoOrganisationSeeder::class);
    actAsTenant(DemoOrganisation::tenant());

    return User::query()->where('email', DemoOrganisation::ADMIN_EMAIL)->sole();
}

/** Signs a demo persona in (by e-mail) in place of whoever was signed in. */
function actAsDemoPersona(string $email): User
{
    $user = User::query()->where('email', $email)->sole();
    app('auth')->forgetGuards();
    test()->actingAs($user);

    return $user;
}

/**
 * Every screen of the panel as the signed-in user reaches it: each page, each register's list and create page, and every
 * other page of a register (view, edit and custom record pages) for the register's oldest and newest record. Sign in a
 * user who sees every record (the tenant super admin) to build it; screenAllowed() then judges each screen per persona.
 *
 * @return list<array{label: string, url: string, page: class-string<Page>, resource: ?class-string<resource>, record: ?Model}>
 */
function populatedScreens(): array
{
    $panel = Filament::getPanel('admin');
    $panel->boot();
    $screens = [];
    foreach ($panel->getPages() as $page) {
        /** @var class-string<Page> $page */
        $url = rescue(fn () => $page::getUrl(), null, false);
        if ($url !== null) {
            $screens[] = ['label' => class_basename($page), 'url' => $url, 'page' => $page, 'resource' => null, 'record' => null];
        }
    }
    foreach ($panel->getResources() as $resource) {
        /** @var class-string<resource> $resource */
        foreach ($resource::getPages() as $name => $registration) {
            $page = $registration->getPage();
            if (in_array($name, ['index', 'create'], true)) {
                $screens[] = ['label' => class_basename($resource).' '.$name, 'url' => $resource::getUrl($name), 'page' => $page, 'resource' => $resource, 'record' => null];

                continue;
            }
            foreach (populatedSampleRecords($resource) as $record) {
                $url = rescue(fn () => $resource::getUrl($name, ['record' => $record]), null, false);
                if ($url !== null) {
                    $screens[] = ['label' => class_basename($resource)." {$name} #{$record->getKey()}", 'url' => $url, 'page' => $page, 'resource' => $resource, 'record' => $record];
                }
            }
        }
    }

    return $screens;
}

/** Whether the signed-in user may open the screen, by the panel's own rules (the register's and the page's). */
function screenAllowed(array $screen): bool
{
    return (bool) rescue(function () use ($screen) {
        ['page' => $page, 'resource' => $resource, 'record' => $record] = $screen;
        if (! $page::canAccess($record ? ['record' => $record] : [])) {
            return false;
        }
        if ($resource === null) {
            return true;
        }
        // A register's own rules (Filament checks these when the page mounts, not in the page's canAccess()).
        if (! $resource::canAccess()) {
            return false;
        }

        return match (true) {
            is_subclass_of($page, CreateRecord::class) => $resource::canCreate(),
            $record !== null && is_subclass_of($page, EditRecord::class) => $resource::canEdit($record),
            $record !== null => $resource::canView($record),
            default => true,
        };
    }, false, false);
}

/**
 * The oldest and newest record of a register that the signed-in user's queries reach.
 *
 * @param  class-string<resource>  $resource
 * @return list<Model>
 */
function populatedSampleRecords(string $resource): array
{
    return rescue(function () use ($resource) {
        $key = $resource::getModel()::make()->getQualifiedKeyName();
        $first = $resource::getEloquentQuery()->reorder($key)->first();
        $last = $resource::getEloquentQuery()->reorder($key, 'desc')->first();

        return array_values(array_filter([$first, $last?->is($first) ? null : $last]));
    }, [], false);
}

/**
 * Works every register's table the way a person does, as the signed-in user: searches it, sorts it by each sortable
 * column both ways, and applies each select, ternary and toggle filter (one at a time). Returns the steps that failed.
 *
 * @return array{steps: int, failures: array<string, string>}
 */
function workEveryTable(): array
{
    $panel = Filament::getPanel('admin');
    $panel->boot();
    Filament::setCurrentPanel($panel);
    $steps = 0;
    $failures = [];
    foreach ($panel->getResources() as $resource) {
        if (! $resource::hasPage('index') || ! rescue(fn () => $resource::canAccess(), false, false)) {
            continue;
        }
        $page = $resource::getPages()['index']->getPage();
        $label = class_basename($resource);
        try {
            $livewire = Livewire::test($page);
            $table = $livewire->instance()->getTable();
        } catch (Throwable $e) {
            $failures["{$label} open"] = $e::class.': '.strtok($e->getMessage(), "\n");

            continue;
        }
        $work = [];
        if ($table->isSearchable()) {
            $work['search'] = fn () => $livewire->searchTable('a')->searchTable(null);
        }
        foreach ($table->getColumns() as $name => $column) {
            if ($column->isSortable()) {
                $work["sort {$name}"] = fn () => $livewire->sortTable($name, 'asc')->sortTable($name, 'desc');
            }
        }
        foreach ($table->getFilters() as $name => $filter) {
            $value = match (true) {
                $filter instanceof TernaryFilter => true,
                $filter instanceof SelectFilter => array_key_first(rescue(fn () => $filter->getOptions(), [], false) ?: []),
                $filter::class === Filter::class => true,
                default => null,
            };
            if ($value === null) {
                continue;
            }
            if ($filter instanceof SelectFilter && $filter->isMultiple()) {
                $value = [$value];
            }
            $work["filter {$name}"] = fn () => $livewire->filterTable($name, $value)->call('removeTableFilters');
        }
        foreach ($work as $step => $run) {
            $steps++;
            try {
                $run();
            } catch (Throwable $e) {
                $failures["{$label} {$step}"] = $e::class.': '.strtok($e->getMessage(), "\n");
            }
        }
    }

    return ['steps' => $steps, 'failures' => $failures];
}

/**
 * Submits every create form empty (it must answer with validation errors, never an exception) and saves every edit form
 * of the registers' sample records unchanged (an unchanged record must save, or at worst answer with validation errors).
 *
 * @param  list<array{label: string, url: string, page: string, resource: ?string, record: ?Model}>  $screens
 * @return array{submitted: int, exceptions: array<string, string>, unchanged_refused: array<string, string>}
 */
function submitEveryForm(array $screens): array
{
    $panel = Filament::getPanel('admin');
    $panel->boot();
    Filament::setCurrentPanel($panel);
    $submitted = 0;
    $exceptions = [];
    $refused = [];
    foreach ($screens as $screen) {
        $page = $screen['page'];
        $isCreate = is_subclass_of($page, CreateRecord::class) && $screen['record'] === null;
        $isEdit = is_subclass_of($page, EditRecord::class) && $screen['record'] !== null;
        if ((! $isCreate && ! $isEdit) || ! screenAllowed($screen)) {
            continue;
        }
        $submitted++;
        try {
            $livewire = $isCreate
                ? Livewire::test($page)->call('create')
                : Livewire::test($page, ['record' => $screen['record']->getRouteKey()])->call('save');
            $errors = $livewire->errors()->toArray();
            if ($isEdit && $errors !== []) {
                $refused[$screen['label']] = collect($errors)->map(fn ($messages, $field) => $field.': '.$messages[0])->take(3)->implode('; ');
            }
        } catch (Throwable $e) {
            $exceptions[$screen['label']] = $e::class.': '.strtok($e->getMessage(), "\n").' @ '.str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine();
        }
    }

    return ['submitted' => $submitted, 'exceptions' => $exceptions, 'unchanged_refused' => $refused];
}

/**
 * Opens each screen as the signed-in user and returns what happened, with whether the user was allowed to open it.
 *
 * @param  list<array{label: string, url: string, page: string, resource: ?string, record: ?Model}>  $screens
 * @return list<array{label: string, url: string, allowed: bool, status: int, ms: float, queries: int, error: ?string}>
 */
function openScreens(array $screens): array
{
    $results = [];
    $counter = new ArrayObject(['queries' => 0]);
    DB::listen(function () use ($counter) {
        $counter['queries']++;
    });
    foreach ($screens as $screen) {
        $allowed = screenAllowed($screen);
        $counter['queries'] = 0;
        $started = microtime(true);
        $response = test()->get($screen['url']);
        $ms = (microtime(true) - $started) * 1000;
        $results[] = [
            'label' => $screen['label'],
            'url' => $screen['url'],
            'allowed' => $allowed,
            'status' => $response->getStatusCode(),
            'ms' => round($ms, 1),
            'queries' => $counter['queries'],
            'error' => $response->exception ? strtok($response->exception::class.': '.$response->exception->getMessage(), "\n") : null,
        ];
    }

    return $results;
}
