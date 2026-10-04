<?php

// UX.15 closure C.2: inventory of Filament resources from the registered admin panel plus source metrics.
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;

$panel = Filament::getPanel('admin');
Filament::setCurrentPanel($panel);
$t = app(TenantContext::class);
$tenant = $t->bypass(fn () => Tenant::query()->where('slug', 'demo')->first());
$t->set($tenant);
auth()->setUser(User::query()->where('email', 'kavya.menon@demo.local')->first());
$rows = [];
foreach ($panel->getResources() as $resource) {
    $ref = new ReflectionClass($resource);
    $dir = dirname($ref->getFileName());
    $src = file_get_contents($ref->getFileName());
    $all = $src;
    foreach (glob($dir.'/{Pages,Schemas,Tables,RelationManagers}/*.php', GLOB_BRACE) ?: [] as $f) {
        $all .= file_get_contents($f);
    }
    $model = $resource::getModel();
    $table = (new $model)->getTable();
    $traits = class_uses_recursive($model);
    $pages = [];
    foreach ($resource::getPages() as $key => $reg) {
        $pages[$key] = class_basename($reg->getPage());
    }
    $relations = array_map(fn ($r) => class_basename(is_string($r) ? $r : get_class($r)), $resource::getRelations());
    $cols = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
    $rows[] = [
        'resource' => class_basename($resource), 'slug' => $resource::getSlug(), 'group' => (string) rescue(fn () => $resource::getNavigationGroup() ?? '', '?', false),
        'label' => (string) rescue(fn () => $resource::getPluralModelLabel(), class_basename($resource), false),
        'nav' => (bool) rescue(fn () => $resource::shouldRegisterNavigation(), false, false),
        'access_kavya' => (bool) rescue(fn () => $resource::canAccess(), false, false), 'model' => class_basename($model), 'table' => $table,
        'pages' => $pages, 'relations' => $relations,
        'status' => in_array('status', $cols, true), 'employee' => in_array('employee_id', $cols, true), 'effective' => in_array('effective_from', $cols, true),
        'effective_trait' => in_array('App\Support\EffectiveDating\HasEffectiveDates', $traits, true),
        'columns' => preg_match_all('/Column::make\(/', $all), 'filters' => preg_match_all('/Filter::make\(/', $all),
        'fields' => preg_match_all('/(TextInput|Select|Toggle|DatePicker|Textarea|Repeater|KeyValue|FileUpload|Checkbox|Radio|RichEditor|TagsInput|TimePicker|DateTimePicker|ColorPicker|CheckboxList|MarkdownEditor)::make\(/', $all),
        'actions' => preg_match_all('/Action::make\(/', $all),
        'employee_cols' => preg_match_all("/Column::make\('employee\./", $all),
    ];
}
file_put_contents(__DIR__.'/inventory.json', json_encode($rows, JSON_PRETTY_PRINT));
echo count($rows)." resources\n";
