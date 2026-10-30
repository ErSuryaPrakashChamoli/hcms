<?php

namespace App\Filament\Support;

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Exit\Models\ClearanceChecklist;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Expense\Models\ExpenseLocationGroup;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Feedback\Services\FeedbackTemplates;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Performance\Services\GoalTemplates;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\TicketCategory;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Builds the settings form for a policy type from config('peopleos.policies.types').
 *
 * Field types: number, boolean (toggle), yes_no (Yes / No radio), select, radio (inline options), multiselect,
 * checkboxes, time (HH:MM), tags (a list of strings, e.g. IP addresses), text, textarea, repeater, and section (a
 * titled group whose fields stay flat in the settings, so services keep reading settings by key). Any field or section
 * may carry `visible_when` => ['key' => other field, 'in' => [values]] (or 'equals' => value / 'filled' => true) to show
 * only when its parent answer needs it, like HROne's follow-up questions; and `help` for helper text.
 */
final class PolicySettingsSchema
{
    public const STATE = 'settings';

    /** @return array<int, Component> */
    public static function components(string $type): array
    {
        $fields = config("peopleos.policies.types.{$type}.fields", []);

        if ($fields === []) {
            return [KeyValue::make(self::STATE)->label('Settings')->keyLabel('Setting')->valueLabel('Value')->columnSpanFull()];
        }
        $parents = self::parents($fields);

        return array_map(fn (array $f) => self::component($f, self::STATE.'.', $parents), $fields);
    }

    /**
     * The policy type's questions split into wizard steps, like HROne's policy screens: the questions outside any section
     * ("General"), then one step per section and one per table (repeater). Fields keep their flat settings keys; a type
     * without declared questions gets one free-form "Settings" step.
     *
     * @return list<array{key: string, label: string, help: ?string, components: array<int, Component>}>
     */
    public static function steps(string $type): array
    {
        $fields = config("peopleos.policies.types.{$type}.fields", []);
        if ($fields === []) {
            return [['key' => '_settings', 'label' => 'Settings', 'help' => null, 'components' => self::components($type)]];
        }
        $parents = self::parents($fields);
        $general = array_values(array_filter($fields, fn (array $f) => ! in_array($f['type'], ['section', 'repeater'], true)));
        $steps = $general === [] ? [] : [['key' => '_general', 'label' => 'General', 'help' => null, 'components' => array_map(fn (array $f) => self::component($f, self::STATE.'.', $parents), $general)]];
        foreach ($fields as $field) {
            if ($field['type'] === 'section') {
                $group = Group::make(array_map(fn (array $f) => self::component($f, self::STATE.'.', $parents), $field['fields'] ?? []))->columns(2)->columnSpanFull();
                $steps[] = ['key' => $field['key'], 'label' => $field['label'], 'help' => $field['help'] ?? null, 'components' => [self::visibility($group, $field, self::STATE.'.')]];
            } elseif ($field['type'] === 'repeater') {
                $steps[] = ['key' => $field['key'], 'label' => $field['label'], 'help' => $field['help'] ?? null, 'components' => [self::component($field, self::STATE.'.', $parents)]];
            }
        }

        return $steps;
    }

    /**
     * A policy's answers grouped as the wizard's steps, for its review: only the questions that apply (a follow-up whose
     * parent says no is left out). Tables list their rows by their first column.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array{label: string, rows: list<array{0: string, 1: string}>}>
     */
    public static function summary(string $type, array $settings): array
    {
        $fields = config("peopleos.policies.types.{$type}.fields", []);
        if ($fields === []) {
            return $settings === [] ? [] : [['label' => 'Settings', 'rows' => array_map(fn ($k) => [(string) $k, is_scalar($settings[$k]) ? (string) $settings[$k] : json_encode($settings[$k])], array_keys($settings))]];
        }
        $row = function (array $f) use ($settings): array {
            $value = $settings[$f['key']] ?? null;
            if ($f['type'] === 'repeater' && is_array($value) && $value !== []) {
                $first = $f['fields'][0] ?? null;

                return [$f['label'], $first ? implode(', ', array_map(fn ($r) => self::display($first, $r[$first['key']] ?? null), $value)) : count($value).' row(s)'];
            }

            return [$f['label'], self::display($f, $value)];
        };
        $general = [];
        $groups = [];
        foreach ($fields as $f) {
            if (! self::shown($f, $settings)) {
                continue;
            }
            match ($f['type']) {
                'section' => $groups[] = ['label' => $f['label'], 'rows' => array_values(array_map($row, array_filter($f['fields'] ?? [], fn (array $c) => self::shown($c, $settings))))],
                'repeater' => $groups[] = ['label' => $f['label'], 'rows' => [$row($f)]],
                default => $general[] = $row($f),
            };
        }

        return $general === [] ? $groups : [['label' => 'General', 'rows' => $general], ...$groups];
    }

    /**
     * Read-only view of a version's settings with the form's labels and sections (raw keys only when the type has none).
     *
     * @return array<int, Component>
     */
    public static function entries(string $type): array
    {
        $fields = config("peopleos.policies.types.{$type}.fields", []);
        if ($fields === []) {
            return [KeyValueEntry::make(self::STATE)->columnSpanFull()];
        }

        return array_map(fn (array $f) => self::entry($f), $fields);
    }

    /** Every field of a type, sections flattened. @return list<array<string, mixed>> */
    public static function flatFields(string $type): array
    {
        return self::flatten(config("peopleos.policies.types.{$type}.fields", []));
    }

    /** Human-readable value of a field, as the settings view shows it. */
    public static function display(array $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        return match ($field['type']) {
            'boolean', 'yes_no' => $value ? 'Yes' : 'No',
            'select', 'radio' => (string) (self::options($field)[$value] ?? $value),
            'multiselect', 'checkboxes' => implode(', ', array_map(fn ($v) => (string) (self::options($field)[$v] ?? $v), (array) $value)),
            'tags' => implode(', ', (array) $value),
            'repeater' => count((array) $value).' row(s)',
            default => is_scalar($value) ? (string) $value : json_encode($value),
        };
    }

    /** @param  list<string>  $parents keys other fields depend on (made live) */
    private static function component(array $field, string $prefix, array $parents): Component
    {
        $name = $prefix.$field['key'];

        if ($field['type'] === 'section') {
            $section = Section::make($field['label'])->compact()->columns(2)->columnSpanFull()
                ->schema(array_map(fn (array $f) => self::component($f, $prefix, $parents), $field['fields'] ?? []));
            if (isset($field['help'])) {
                $section->description($field['help']);
            }

            return self::visibility($section, $field, $prefix);
        }

        $component = match ($field['type']) {
            'number' => TextInput::make($name)->numeric()->minValue($field['min'] ?? null)->maxValue($field['max'] ?? null)->suffix($field['suffix'] ?? null),
            'boolean' => Toggle::make($name),
            'yes_no' => Radio::make($name)->boolean()->inline(),
            'select' => Select::make($name)->options(fn () => self::options($field)),
            'radio' => Radio::make($name)->options(fn () => self::options($field))->inline(),
            'multiselect' => Select::make($name)->options(fn () => self::options($field))->multiple(),
            'checkboxes' => CheckboxList::make($name)->options(fn () => self::options($field))->columns(4),
            'time' => TimePicker::make($name)->seconds(false)->native(false),
            'tags' => TagsInput::make($name)->placeholder($field['placeholder'] ?? null),
            'textarea' => Textarea::make($name)->rows(3)->maxLength($field['max'] ?? 1000)->columnSpanFull(),
            'repeater' => Repeater::make($name)
                ->schema(array_map(fn (array $f) => self::component($f, '', self::parents($field['fields'] ?? [])), $field['fields'] ?? []))
                ->columns(3)
                ->collapsible()
                // `item_label` => 'options': the row is named by its first column's option label (e.g. an expense category as
                // "Name · Amount · Group"), not its stored value.
                ->itemLabel(fn (array $state) => ($field['item_label'] ?? null) === 'options' && isset($field['fields'][0]) && filled($state[$field['fields'][0]['key']] ?? null)
                    ? self::display($field['fields'][0], $state[$field['fields'][0]['key']])
                    : ($state[$field['fields'][0]['key'] ?? ''] ?? null))
                ->columnSpanFull(),
            default => TextInput::make($name)->maxLength(255),
        };

        $component->label($field['label']);
        if (isset($field['help'])) {
            $component->helperText($field['help']);
        }
        if (in_array($field['key'], $parents, true) && method_exists($component, 'live')) {
            $component->live();
        }
        if (in_array($field['type'], ['yes_no', 'radio', 'checkboxes', 'tags'], true)) {
            $component->columnSpanFull();
        }

        return self::visibility($component, $field, $prefix);
    }

    private static function entry(array $field): Component
    {
        if ($field['type'] === 'section') {
            return Section::make($field['label'])->compact()->columns(2)->columnSpanFull()
                ->schema(array_map(fn (array $f) => self::entry($f), $field['fields'] ?? []))
                ->visible(fn ($record) => self::shown($field, (array) ($record?->settings ?? [])));
        }

        return TextEntry::make(self::STATE.'.'.$field['key'])->label($field['label'])
            ->state(fn ($record) => self::display($field, $record?->settings[$field['key']] ?? null))
            ->visible(fn ($record) => self::shown($field, (array) ($record?->settings ?? [])));
    }

    private static function visibility(Component $component, array $field, string $prefix): Component
    {
        if (! isset($field['visible_when'])) {
            return $component;
        }
        $rule = $field['visible_when'];

        return $component->visible(fn (Get $get) => self::matches($rule, $get($prefix.$rule['key'])));
    }

    /** Whether a field is shown for these settings (the read-only view hides follow-ups whose parent says no). */
    private static function shown(array $field, array $settings): bool
    {
        return ! isset($field['visible_when']) || self::matches($field['visible_when'], $settings[$field['visible_when']['key']] ?? null);
    }

    private static function matches(array $rule, mixed $value): bool
    {
        return match (true) {
            array_key_exists('in', $rule) => in_array($value, $rule['in'], false) || (is_array($value) && array_intersect($value, $rule['in']) !== []),
            array_key_exists('equals', $rule) => $value == $rule['equals'],
            default => in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true) || (is_string($value) && ! in_array($value, ['', '0', 'false', 'no', 'none'], true)) || (is_array($value) && $value !== []),
        };
    }

    /** @return list<string> keys some other field's visibility depends on */
    private static function parents(array $fields): array
    {
        return array_values(array_unique(array_map(fn (array $f) => $f['visible_when']['key'], array_filter(self::flatten($fields, true), fn (array $f) => isset($f['visible_when'])))));
    }

    /** @return list<array<string, mixed>> */
    private static function flatten(array $fields, bool $keepSections = false): array
    {
        $out = [];
        foreach ($fields as $f) {
            if ($f['type'] === 'section') {
                if ($keepSections) {
                    $out[] = $f;
                }
                array_push($out, ...self::flatten($f['fields'] ?? [], $keepSections));

                continue;
            }
            $out[] = $f;
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function options(array $field): array
    {
        return match ($field['options_from'] ?? null) {
            'leave_types' => LeaveType::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get()->mapWithKeys(fn ($t) => [$t->code => "{$t->name} ({$t->code})"])->all(),
            'loan_types' => (array) config('peopleos.loans.types', []),
            'shifts' => Shift::query()->where('status', 'active')->orderBy('name')->get()->mapWithKeys(fn ($s) => [$s->code => "{$s->name} ({$s->code})"])->all(),
            'roles' => Role::query()->orderBy('name')->pluck('name', 'slug')->all(),
            'clearance_checklists' => ClearanceChecklist::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            // HROne setup parity HP-8f: a field may name the letter types it offers (`letter_types`, e.g. PIP letters).
            'letter_templates' => LetterTemplate::query()->where('status', 'active')->when(isset($field['letter_types']), fn ($q) => $q->whereIn('type', (array) $field['letter_types']))->orderBy('name')->pluck('name', 'id')->all(),
            'ticket_categories' => TicketCategory::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'service_definitions' => ServiceDefinition::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'login_disable_states' => SecurityPolicy::loginDisableOptions(),
            'users' => [0 => 'Nobody'] + User::forCurrentTenant()->where('status', UserStatus::Active->value)->orderBy('name')->pluck('name', 'id')->all(),
            // HROne setup parity EX-B: expense masters and periods for the expense policy.
            'expense_categories' => ExpenseCategory::query()->with('group')->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get()->mapWithKeys(fn (ExpenseCategory $c) => [$c->id => $c->displayLabel()])->all(),
            'expense_groups' => ExpenseGroup::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'expense_location_groups' => ExpenseLocationGroup::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'expense_periods' => (array) config('peopleos.expense.periods', []),
            // HROne setup parity AS-B: asset types, sub types and products for the asset entitlement policy.
            'asset_categories' => AssetCategory::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'asset_subtypes' => AssetSubtype::query()->with('category')->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get()
                ->mapWithKeys(fn (AssetSubtype $s) => [$s->id => $s->name.' ('.$s->category?->name.')'])->all(),
            'asset_models' => AssetModel::query()->with('category')->where('status', 'active')->orderBy('name')->get()
                ->mapWithKeys(fn (AssetModel $m) => [$m->id => $m->name.' ('.$m->category?->name.')'])->all(),
            // Owner decision 2026-10-29 (AS-B): the same source as the Setup centre (SetupCatalogue::optionsFor).
            'exit_types' => (array) config('peopleos.exit.types', []),
            'weekdays' => ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'],
            // HROne setup parity CA-1: the same sources as the Setup centre (SetupCatalogue::optionsFor).
            'articles' => SetupCatalogue::articleOptions(),
            'skill_scales' => SetupCatalogue::skillScaleOptions(),
            'dashboards' => Dashboard::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'industries' => (array) config('peopleos.organisation.industries', []),
            // HROne setup parity HP-9c: the same source as the Setup centre (SetupCatalogue::optionsFor).
            'certificate_templates' => SetupCatalogue::certificateTemplateOptions(),
            // HROne setup parity P2 / P3: upload types (import approval) and Approval Center request types (inbox actions).
            'import_types' => ImportTypes::labels(),
            'approval_kinds' => ApprovalCenter::kinds(),
            // HROne parity RQ-2: the request types of the request registry (Notify others).
            'request_types' => RequestRegistry::options(),
            // HROne setup parity P4: the same sources as the Setup centre (SetupCatalogue::optionsFor).
            'employees' => SetupCatalogue::employeeOptions(),
            'employee_task_kinds' => SelfServiceLock::TASK_KINDS,
            'ess_request_kinds' => SelfServiceLock::REQUEST_KINDS,
            // HROne setup parity P13: active feedback templates of the field's process (exit interviews, confirmation …).
            'feedback_templates' => FeedbackTemplates::options($field['process'] ?? null),
            // HROne setup parity HP-8d / HP-8e: the same sources as the Setup centre (SetupCatalogue::optionsFor).
            'goal_templates' => GoalTemplates::options(),
            'competencies' => SetupCatalogue::competencyOptions(),
            default => $field['options'] ?? [],
        };
    }
}
