<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Expense\Models\ExpenseLocationGroup;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\GeoDashboard;
use App\Domain\Experience\Services\HomeComposer;
use App\Domain\Experience\Services\MySpace;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Feedback\Services\FeedbackTemplates;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Services\GoalTemplates;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\Skills\Models\SkillScale;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The Setup catalogue (config peopleos_setup, HROne parity): per module, the typed tenant settings it reads, its
 * policy types and its configuration screens. Settings are validated against their declared type and saved
 * through SettingsRepository (each change audited with the reason given); screens are offered only when the
 * viewer can open them.
 */
final class SetupCatalogue
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array<string, array<string, mixed>> */
    public function modules(): array
    {
        return (array) config('peopleos_setup.modules', []);
    }

    /** @return array<string, mixed> */
    public function module(string $key): array
    {
        return $this->modules()[$key] ?? throw new RuntimeException("Unknown setup module [{$key}].");
    }

    /** Modules with anything this user may see or change. @return array<string, array<string, mixed>> */
    public function visibleModules(User $user): array
    {
        return array_filter($this->modules(), fn (array $module, string $key) => $this->settingsFor($key) !== [] && $user->can('settings.view')
            || $this->policyTypes($key) !== [] && $user->can('policy.view')
            || $this->links($key, $user) !== [], ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Settings of a module with their current and default values.
     *
     * @return list<array<string, mixed>>
     */
    public function settingsFor(string $module): array
    {
        $defaults = (array) config('peopleos.settings', []);

        return array_map(fn (array $s) => $s + [
            'default' => $defaults[$s['key']] ?? null,
            'value' => $this->settings->get($s['key'], $defaults[$s['key']] ?? null),
            'field' => self::field($s['key']),
            'locked' => self::locked($s),
        ], (array) ($this->module($module)['settings'] ?? []));
    }

    /**
     * HROne setup parity HP-9d ("This is a one-time setting which cannot be undone"): a setting may name a check
     * (`locked_after` => [class, method], answering true once the setting may no longer change, e.g. once the reward points
     * ledger has an entry). A locked setting is shown read-only and save() refuses to change it.
     */
    public static function locked(array $setting): bool
    {
        $check = $setting['locked_after'] ?? null;
        if (! is_array($check) || count($check) !== 2 || ! class_exists((string) $check[0])) {
            return false;
        }

        return (bool) app((string) $check[0])->{(string) $check[1]}();
    }

    /** Form field name for a setting key (dots would nest the form state). */
    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** @return array<int|string, string> */
    public function optionsFor(array $setting): array
    {
        return match ($setting['options_from'] ?? ($setting['type'] === 'roles' ? 'roles' : null)) {
            'leave_types' => LeaveType::query()->orderBy('name')->pluck('name', 'code')->all(),
            'roles' => Role::query()->orderBy('name')->pluck('name', 'slug')->all(),
            'loan_types' => (array) config('peopleos.loans.types', []),
            'ticket_categories' => TicketCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            'service_definitions' => ServiceDefinition::query()->orderBy('name')->pluck('name', 'id')->all(),
            'login_disable_states' => SecurityPolicy::loginDisableOptions(),
            'users' => [0 => 'Nobody'] + User::forCurrentTenant()->where('status', UserStatus::Active->value)->orderBy('name')->pluck('name', 'id')->all(),
            // HROne setup parity (Expense): the expense masters a setting may list (e.g. categories with their own workflow).
            'expense_categories' => ExpenseCategory::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'expense_groups' => ExpenseGroup::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'expense_location_groups' => ExpenseLocationGroup::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'expense_periods' => (array) config('peopleos.expense.periods', []),
            // HROne setup parity AS-B: the asset masters (the same sources as the asset policy).
            'asset_categories' => AssetCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            'asset_subtypes' => AssetSubtype::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'asset_models' => AssetModel::query()->orderBy('name')->pluck('name', 'id')->all(),
            // Owner decision 2026-10-29 (AS-B): exit types (exceptions to the return requests raised at exit).
            'exit_types' => (array) config('peopleos.exit.types', []),
            // HROne setup parity CA-1: published HR articles (e.g. the privacy policy), skill scales (profile proficiency),
            // dashboards (shared views) and the industry vocabulary.
            'articles' => self::articleOptions(),
            'skill_scales' => self::skillScaleOptions(),
            'dashboards' => Dashboard::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'industries' => (array) config('peopleos.organisation.industries', []),
            // HROne setup parity CA-6: the sections a directory card may show.
            'directory_sections' => (array) config('peopleos.directory.sections', []),
            // HROne parity MS-1: the My space items (registry keys), for the items an organisation leaves out of My space.
            'myspace_items' => app(MySpace::class)->options(),
            // HROne parity CA-9 (Access rights): Home cards (widget access), calendar events (calendar access) and the
            // audiences of the geo dashboard (geo access).
            'home_blocks' => HomeComposer::blockOptions(),
            'calendar_events' => (array) config('peopleos.calendar.events', []),
            'geo_audiences' => GeoDashboard::AUDIENCES,
            // HROne setup parity HP-9c: letter templates a badge certificate may use (by code).
            'certificate_templates' => self::certificateTemplateOptions(),
            // HROne setup parity P2 / P3: upload types (import approval) and Approval Center request types (inbox actions).
            'import_types' => ImportTypes::labels(),
            'approval_kinds' => ApprovalCenter::kinds(),
            // HROne parity RQ-2: the request types of the request registry (Notify others).
            'request_types' => RequestRegistry::options(),
            // HROne setup parity P4: employees (as the viewer's scope shows them), employee task kinds and self-service
            // request kinds (employee request lock).
            'employees' => self::employeeOptions(),
            'employee_task_kinds' => SelfServiceLock::TASK_KINDS,
            'ess_request_kinds' => SelfServiceLock::REQUEST_KINDS,
            // HROne setup parity P13: active feedback templates (of the setting's process, when it names one).
            'feedback_templates' => FeedbackTemplates::options($setting['process'] ?? null),
            // HROne setup parity HP-8d / HP-8e: active goal templates (by code) and competencies (by code).
            'goal_templates' => GoalTemplates::options(),
            'competencies' => self::competencyOptions(),
            default => (array) ($setting['options'] ?? []),
        };
    }

    /**
     * HROne setup parity P4: employees by id, "Name (code)", within the viewer's access scope (a setting cannot name
     * someone the person saving it cannot see).
     *
     * @return array<int, string>
     */
    public static function employeeOptions(): array
    {
        return Employee::query()->with('person')->orderBy('employee_code')->limit(5000)->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => trim(($e->display_name ?? '').' ('.$e->employee_code.')')])->all();
    }

    /** @return array<string, string> code => name of the active competencies (goal sheet policy competency details) */
    public static function competencyOptions(): array
    {
        return Competency::query()->where('status', ActiveStatus::Active)->orderBy('name')->get()->mapWithKeys(fn (Competency $c) => [$c->code => "{$c->name} ({$c->code})"])->all();
    }

    /** @return array<string, string> code => name of the active badge certificate and appreciation letter templates */
    public static function certificateTemplateOptions(): array
    {
        return LetterTemplate::query()->where('status', 'active')->whereIn('type', ['badge_certificate', 'appreciation'])->orderBy('name')->pluck('name', 'code')->all();
    }

    /** @return array<int, string> published HR articles */
    public static function articleOptions(): array
    {
        return Article::query()->where('status', 'published')->orderBy('title')->pluck('title', 'id')->all();
    }

    /** @return array<int, string> 0 = PeopleOS's built-in proficiency list, then the active skill scales */
    public static function skillScaleOptions(): array
    {
        return [0 => 'Built-in (Beginner, Intermediate, Advanced, Expert)'] + SkillScale::query()->where('status', 'active')->whereNotNull('current_version_id')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Rows of a matrix setting: `rows` itself or the config list named by `rows_from`. @return array<string, string> */
    public static function matrixRows(array $setting): array
    {
        return isset($setting['rows_from']) ? (array) config($setting['rows_from'], []) : (array) ($setting['rows'] ?? []);
    }

    /** A stored matrix as the form shows it: each row's ticked columns. @return array<string, list<string>> */
    public static function matrixToForm(array $setting, mixed $value): array
    {
        $out = [];
        foreach (array_keys(self::matrixRows($setting)) as $row) {
            $out[(string) $row] = array_values(array_map('strval', array_keys(array_filter((array) (((array) $value)[$row] ?? [])))));
        }

        return $out;
    }

    /**
     * Validate and save a module's settings; only changed values are written. Returns the keys changed.
     *
     * @param  array<string, mixed>  $values  keyed by form field name
     * @return list<string>
     */
    public function save(string $module, array $values, string $reason, User $user): array
    {
        if (! $user->can('settings.update')) {
            throw new RuntimeException('You cannot change settings.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the change.');
        }

        $changes = [];
        $replacedFiles = [];
        foreach ($this->settingsFor($module) as $setting) {
            if (! array_key_exists($setting['field'], $values)) {
                continue;
            }
            $value = $this->cast($setting, $values[$setting['field']]);
            // A file setting compares paths: the current file is not checked again (it may have been removed meanwhile).
            $current = $setting['type'] === 'file' ? (filled($setting['value']) ? (string) $setting['value'] : null) : $this->cast($setting, $setting['value']);
            if ($value !== $current && $setting['locked']) {
                throw new RuntimeException("{$setting['label']} is a one-time setting: it can no longer be changed.");
            }
            if ($value !== $current) {
                $changes[$setting['key']] = $value;
                if ($setting['type'] === 'file' && $current !== null) {
                    $replacedFiles[] = $current;
                }
            }
        }

        DB::transaction(function () use ($changes, $reason) {
            foreach ($changes as $key => $value) {
                $this->settings->set($key, $value, $reason);
            }
        });
        // HROne setup parity CA-1: a replaced or cleared file is removed once the new value is saved.
        foreach ($replacedFiles as $path) {
            app(SettingFiles::class)->discard($path);
        }

        return array_keys($changes);
    }

    /**
     * Policy types of a module with how many policies each has.
     *
     * @return list<array{type: string, label: string, policies: int, url: ?string, create_url: ?string}>
     */
    public function policyTypes(string $module): array
    {
        $types = (array) config('peopleos.policies.types', []);
        $counts = Policy::query()->whereIn('type', (array) ($this->module($module)['policy_types'] ?? []))->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');

        return array_values(array_map(fn (string $type) => [
            'type' => $type,
            'label' => $types[$type]['label'] ?? $type,
            'policies' => (int) ($counts[$type] ?? 0),
            // The list's filters are bound to the `filters` query parameter (Filament ListRecords).
            'url' => $this->url('App\\Filament\\Resources\\Policies\\PolicyResource', ['filters' => ['type' => ['value' => $type]]]),
            'create_url' => auth()->user()?->can('policy.create') ? $this->url('App\\Filament\\Pages\\PolicyWizard', ['type' => $type]) : null,
        ], array_values(array_filter((array) ($this->module($module)['policy_types'] ?? []), fn ($t) => isset($types[$t])))));
    }

    /** @return list<array{label: string, url: string}> configuration screens of the module the user can open */
    public function links(string $module, User $user): array
    {
        $links = [];
        foreach ((array) ($this->module($module)['links'] ?? []) as $link) {
            $class = $link['target'];
            if (! class_exists($class)) {
                continue;
            }
            try {
                $allowed = is_subclass_of($class, Resource::class) ? $class::canAccess() : (is_subclass_of($class, Page::class) ? $class::canAccess() : false);
            } catch (Throwable) {
                $allowed = false;
            }
            if ($allowed && ($url = $this->url($class)) !== null) {
                $links[] = ['label' => $link['label'], 'url' => $url];
            }
        }

        return $links;
    }

    private function url(string $class, array $parameters = []): ?string
    {
        try {
            return is_subclass_of($class, Resource::class) ? $class::getUrl('index', $parameters) : $class::getUrl($parameters);
        } catch (Throwable) {
            return null;
        }
    }

    private function cast(array $setting, mixed $value): mixed
    {
        $type = $setting['type'];
        if ($type === 'boolean') {
            return (bool) $value;
        }
        if ($type === 'number') {
            // HROne setup parity HP-9d: a nullable number (e.g. the points value) may be left blank.
            if (($setting['nullable'] ?? false) && ($value === null || $value === '')) {
                return null;
            }
            if ($value === null || $value === '') {
                throw new RuntimeException("{$setting['label']} needs a number.");
            }
            if (! is_numeric($value)) {
                throw new RuntimeException("{$setting['label']} must be a number.");
            }
            $number = $value + 0;
            if (isset($setting['min']) && $number < $setting['min'] || isset($setting['max']) && $number > $setting['max']) {
                throw new RuntimeException("{$setting['label']} must be between {$setting['min']} and {$setting['max']}.");
            }

            return is_float($number) && floor($number) != $number ? (float) $number : (int) $number;
        }
        if ($type === 'multiselect') {
            $options = array_map('strval', array_keys($this->optionsFor($setting)));
            $list = array_values(array_map('strval', array_filter((array) $value, fn ($v) => $v !== null && $v !== '')));
            if (array_diff($list, $options) !== [] && $options !== []) {
                throw new RuntimeException("{$setting['label']} has an option that does not exist.");
            }
            sort($list);

            return $list;
        }
        if ($type === 'date') {
            if ($value === null || $value === '') {
                return null;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $value, 0, 10));
            if ($date === false) {
                throw new RuntimeException("{$setting['label']} must be a date.");
            }

            return $date->format('Y-m-d');
        }
        if ($type === 'matrix') {
            // HROne setup parity CA-1: rows × boolean columns. The form sends each row's ticked columns; the stored value is
            // {row: {column: bool}} for every declared row and column.
            $rows = self::matrixRows($setting);
            $columns = (array) ($setting['columns'] ?? []);
            $given = (array) ($value ?? []);
            if (array_diff(array_map('strval', array_keys($given)), array_map('strval', array_keys($rows))) !== []) {
                throw new RuntimeException("{$setting['label']} has a row that does not exist.");
            }
            $matrix = [];
            foreach (array_keys($rows) as $row) {
                $cells = (array) ($given[$row] ?? []);
                $ticked = array_is_list($cells) ? array_map('strval', $cells) : array_map('strval', array_keys(array_filter($cells)));
                if (array_diff($ticked, array_map('strval', array_keys($columns))) !== []) {
                    throw new RuntimeException("{$setting['label']} has a column that does not exist.");
                }
                foreach (array_keys($columns) as $column) {
                    $matrix[(string) $row][(string) $column] = in_array((string) $column, $ticked, true);
                }
            }

            return $matrix;
        }
        if ($type === 'file') {
            if ($value === null || $value === '' || $value === []) {
                return null;
            }
            $path = is_array($value) ? (string) (array_values($value)[0] ?? '') : (string) $value;

            return app(SettingFiles::class)->assertValid($setting, $path);
        }
        if (($setting['nullable'] ?? false) && ($value === null || $value === '') && in_array($type, ['select', 'roles'], true)) {
            return null;
        }
        if ($type === 'select' || $type === 'roles') {
            $options = $this->optionsFor($setting);
            if ($options !== [] && ! array_key_exists($value, $options)) {
                throw new RuntimeException("Choose a value for {$setting['label']}.");
            }

            return is_int(array_key_first($options)) ? (int) $value : (string) $value;
        }

        $text = trim((string) $value);
        if (isset($setting['max']) && mb_strlen($text) > $setting['max']) {
            throw new RuntimeException("{$setting['label']} can be at most {$setting['max']} characters.");
        }
        if (isset($setting['pattern']) && $text !== '' && preg_match($setting['pattern'], $text) !== 1) {
            throw new RuntimeException("{$setting['label']} is not in the expected format.");
        }

        return $text === '' ? null : $text;
    }
}
