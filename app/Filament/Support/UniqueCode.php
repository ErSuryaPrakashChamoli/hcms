<?php

namespace App\Filament\Support;

use App\Support\Database\UniqueIndexes;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Component as LivewireComponent;
use WeakMap;

/**
 * 2026-11-19 (owner: a duplicate code must be caught while the form is being filled in — the field itself says the
 * code is taken or available — never as a server error on save). A text field whose column the database keeps
 * unique, per organisation (tenant_id + column) or outright, is checked as it is typed: the label says "Available"
 * or "Already in use", the field names the record already holding the value, and the same rule refuses the save,
 * so a clash never reaches the database's unique index from a form.
 *
 * Automatic on record forms (create / edit pages and their drawers): the model comes from the form and the scope from
 * the model's own unique indexes (UniqueIndexes), so the check refuses exactly what the database would — archived
 * rows and rows outside the viewer's access scope included — and never anything else. Repeater items and other
 * forms opt in with ->uniqueCode(Model::class). A clash that still reaches the database (two people saving the same
 * code at once) is turned into a message on the form by DuplicateEntryGuard.
 */
final class UniqueCode
{
    private const DEBOUNCE_MS = 500;

    /** @var WeakMap<TextInput, array{model: string|Closure|null, column: string|null, ignore: Model|int|string|Closure|null, enabled: bool}>|null */
    private static ?WeakMap $optIns = null;

    /** @var WeakMap<TextInput, array<string, array{0: string, 1: ?string}>>|null per field: value => [status, message] for this request */
    private static ?WeakMap $memo = null;

    /** @var array<string, array{0: ?string, 1: string, 2: bool}> model class => [connection, table, tenant-owned] */
    private static array $models = [];

    private static ?Closure $liveCondition = null;

    public static function register(): void
    {
        // ->uniqueCode(Model::class, ignore: …) checks a field outside a record form; ->uniqueCode(enabled: false) leaves a
        // field to a uniqueness check of its own (the hire wizard's employee code, which also ignores case).
        TextInput::macro('uniqueCode', function (string|Closure|null $model = null, ?string $column = null, Model|int|string|Closure|null $ignore = null, bool $enabled = true): TextInput {
            /** @var TextInput $this */
            UniqueCode::optIn($this, $model, $column, $ignore, $enabled);

            return $this;
        });

        // One shared closure, so rendersAlone() can tell whether a form made the field live for reasons of its own.
        self::$liveCondition ??= static fn (TextInput $component): ?bool => self::target($component) !== null ? true : null;

        TextInput::configureUsing(fn (TextInput $input) => $input
            ->live(debounce: self::DEBOUNCE_MS, condition: self::$liveCondition)
            ->afterStateUpdated(static fn (TextInput $component, LivewireComponent $livewire) => self::validateLive($component, $livewire))
            ->partiallyRenderAfterStateUpdated(static fn (TextInput $component): bool => self::rendersAlone($component))
            ->rule(static fn (TextInput $component): ?Closure => self::rule($component), static fn (TextInput $component): bool => self::target($component) !== null)
            ->hint(static fn (TextInput $component): ?string => match (self::status($component)[0] ?? null) {
                'free' => 'Available',
                'taken' => 'Already in use',
                default => null,
            })
            ->hintColor(static fn (TextInput $component): ?string => match (self::status($component)[0] ?? null) {
                'free' => 'success',
                'taken' => 'danger',
                default => null,
            })
            ->hintIcon(
                static fn (TextInput $component): ?string => match (self::status($component)[0] ?? null) {
                    'free' => 'heroicon-m-check-circle',
                    'taken' => 'heroicon-m-x-circle',
                    default => null,
                },
                static fn (TextInput $component): ?string => self::status($component)[1] ?? null,
            ));
    }

    /** ->uniqueCode(): check this field against a model's unique column outside a record form (or with another model). */
    public static function optIn(TextInput $input, string|Closure|null $model, ?string $column, Model|int|string|Closure|null $ignore, bool $enabled = true): void
    {
        self::$optIns ??= new WeakMap;
        self::$optIns[$input] = ['model' => $model, 'column' => $column, 'ignore' => $ignore, 'enabled' => $enabled];
    }

    /**
     * What this field is checked against, or null when it is not a unique column here.
     *
     * @return array{model: class-string<Model>, column: string, perTenant: bool, ignore: int|string|null}|null
     */
    public static function target(TextInput $component): ?array
    {
        // Cheapest checks first: this runs for every text field, several times per render.
        $optIn = self::$optIns[$component] ?? null;
        if ($optIn !== null && ! $optIn['enabled']) {
            return null;
        }
        if ($optIn !== null) {
            $model = $component->evaluate($optIn['model']) ?? $component->getModel();
            $column = $optIn['column'] ?? $component->getName();
            $ignore = $optIn['ignore'] !== null ? $component->evaluate($optIn['ignore']) : $component->getRecord();
        } else {
            $operation = $component->getContainer()->getOperation();
            if (! in_array($operation, ['create', 'edit'], true) || self::insideList($component)) {
                return null;
            }
            $model = $component->getModel();
            $ignore = $component->getRecord();
            // A create form has no record yet; an edit form's record is the one being edited. Anything else (a create
            // drawer on another record's page) is not this model's form, so the field is left alone.
            if ($operation === 'create' ? $ignore !== null : ! (is_string($model) && $ignore instanceof $model)) {
                return null;
            }
            $column = $component->getName();
        }

        if ($model instanceof Model) {
            $model = $model::class;
        }
        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            return null;
        }

        $perTenant = self::scope($model, $column);
        if ($perTenant === null || $component->isDisabled()) {
            return null;
        }

        if ($ignore instanceof Model) {
            $ignore = $ignore instanceof $model && $ignore->exists ? $ignore->getRawOriginal($ignore->getKeyName()) : null;
        }

        return ['model' => $model, 'column' => $column, 'perTenant' => $perTenant, 'ignore' => is_int($ignore) || is_string($ignore) ? $ignore : null];
    }

    /** The validation rule: refuses a value another row already holds (the message names that row when it may be seen). */
    public static function rule(TextInput $component): ?Closure
    {
        $target = self::target($component);
        if ($target === null) {
            return null;
        }
        $label = self::label($component);

        return static function (string $attribute, mixed $value, Closure $fail) use ($target, $label): void {
            if (! is_scalar($value) || blank($value)) {
                return;
            }
            [$status, $message] = self::lookup($target, (string) $value, $label);
            if ($status === 'taken') {
                $fail($message);
            }
        };
    }

    /**
     * Validates the field as it is typed, without Filament's validateOnly(): that throws (skipping the form's own
     * afterStateUpdated hooks) and scrolls the page to the first error on every keystroke.
     */
    public static function validateLive(TextInput $component, LivewireComponent $livewire): void
    {
        if (self::target($component) === null) {
            return;
        }

        $path = $component->getStatePath();
        $root = Str::before($path, '.');
        $messages = [];
        foreach ($component->getValidationMessages() as $rule => $message) {
            $messages["{$path}.{$rule}"] = $message;
        }

        $validator = Validator::make([$root => data_get($livewire, $root)], [$path => $component->getValidationRules()], $messages, [$path => $component->getValidationAttribute()]);

        $livewire->resetErrorBag($path);
        if ($validator->fails()) {
            $livewire->addError($path, (string) $validator->errors()->first($path));
        }
    }

    /**
     * What the label says about the value typed: ['free'|'taken', message], or null when there is nothing to say
     * (not a unique column, empty, the record's own value, or an error under the field already says it).
     *
     * @return array{0: string, 1: ?string}|null
     */
    public static function status(TextInput $component): ?array
    {
        $target = self::target($component);
        $state = $component->getState();
        if ($target === null || ! is_scalar($state) || blank($state)) {
            return null;
        }
        if ($component->getLivewire()->getErrorBag()->has($component->getStatePath())) {
            return null;
        }

        self::$memo ??= new WeakMap;
        $known = self::$memo[$component] ?? [];
        $value = (string) $state;
        $known[$value] ??= self::lookup($target, $value, self::label($component));
        self::$memo[$component] = $known;

        return $known[$value][0] === 'own' ? null : $known[$value];
    }

    /**
     * @param  array{model: class-string<Model>, column: string, perTenant: bool, ignore: int|string|null}  $target
     * @return array{0: string, 1: ?string} 'free' | 'own' (only the record being edited holds it) | 'taken' + message
     */
    private static function lookup(array $target, string $value, string $label): array
    {
        $model = $target['model'];
        // The database's own view: every row of the organisation (or of all, for an outright unique column), archived
        // ones included, regardless of what the viewer may see — that is what its unique index compares.
        $query = $model::query()->withoutGlobalScopes()->where($target['column'], $value);
        if ($target['perTenant']) {
            $tenantId = app(TenantContext::class)->id();
            if ($tenantId === null) {
                return ['free', null]; // no organisation bound: the save is refused anyway (fail closed in BelongsToTenant)
            }
            $query->where('tenant_id', $tenantId);
        }

        $rows = $query->limit(2)->get();
        $holder = $rows->first(fn (Model $row) => $target['ignore'] === null || (string) $row->getKey() !== (string) $target['ignore']);
        if ($holder === null) {
            return [$rows->isEmpty() ? 'free' : 'own', null];
        }

        return ['taken', $value.' is already used by '.self::holderName($model, $holder).'. Choose another '.$label.'.'];
    }

    /** The holder by name when the viewer may see it ("Digital Marketing", "Old Sales (archived)"), else "another department". */
    private static function holderName(string $model, Model $holder): string
    {
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
        $visible = $model::query()->when($softDeletes, fn ($q) => $q->withTrashed())->whereKey($holder->getKey())->first();
        $noun = Str::snake(class_basename($model), ' ');
        if ($visible === null) {
            return 'another '.$noun;
        }

        $attributes = $visible->getAttributes();
        $name = null;
        foreach (['name', 'title', 'label', 'display_name', 'full_name'] as $key) {
            if (is_string($attributes[$key] ?? null) && trim($attributes[$key]) !== '') {
                $name = trim($attributes[$key]);
                break;
            }
        }
        if ($name === null && method_exists($visible, 'auditLabel')) {
            $name = trim((string) $visible->auditLabel());
        }
        if ($name === null || $name === '') {
            return 'another '.$noun;
        }

        $archived = $softDeletes && method_exists($visible, 'trashed') && $visible->trashed();

        return $name.($archived ? ' (archived)' : '');
    }

    /**
     * Whether the column is unique per organisation (true), outright (false), or not unique (null), read from the
     * table's unique indexes. Composite indexes beyond tenant + column are left to the form (and the guard).
     */
    private static function scope(string $model, string $column): ?bool
    {
        if (! isset(self::$models[$model])) {
            $instance = new $model;
            self::$models[$model] = [$instance->getConnectionName(), $instance->getTable(), in_array(BelongsToTenant::class, class_uses_recursive($model), true)];
        }
        [$connection, $table, $tenantOwned] = self::$models[$model];

        foreach (UniqueIndexes::of($connection, $table) as $columns) {
            if ($columns === [$column]) {
                return false;
            }
            if ($tenantOwned && count($columns) === 2 && in_array('tenant_id', $columns, true) && in_array($column, $columns, true)) {
                return true;
            }
        }

        return null;
    }

    /** Fields inside repeater / builder items are rows of a list, not the record's own column. */
    private static function insideList(TextInput $component): bool
    {
        $parent = $component->getContainer()->getParentComponent();
        while ($parent !== null) {
            if ($parent instanceof Repeater || $parent instanceof Builder) {
                return true;
            }
            $parent = $parent->getContainer()->getParentComponent();
        }

        return false;
    }

    /**
     * Re-render only this field after it is typed in when nothing else depends on it: the form did not make it live
     * itself and added no state-updated hooks of its own (those may change other fields, which then need a render).
     */
    private static function rendersAlone(TextInput $component): bool
    {
        if (self::target($component) === null) {
            return false;
        }
        [$isLive, $hooks] = (fn (): array => [$this->isLive, count($this->afterStateUpdated)])->call($component);

        return $isLive === self::$liveCondition && $hooks === 1;
    }

    private static function label(TextInput $component): string
    {
        $label = $component->getLabel();
        $label = trim(strip_tags($label instanceof Htmlable ? $label->toHtml() : (string) $label));

        return $label === '' ? 'value' : Str::lower($label);
    }
}
