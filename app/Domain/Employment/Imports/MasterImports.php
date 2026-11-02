<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Configuration\Services\ConfigurationChanges;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Data import: a master list keyed by code (branches, departments, grades, vendors, asset types …). A row whose code
 * is on file updates that record and any other row creates one; a code may appear once per file. Blank cells keep
 * what is on file. Creates are audited by the model. An update of a governed configuration record goes through the
 * Configuration Change Centre when the organisation requires approval, as the record's edit screen does (the row then
 * names the change that waits).
 *
 * A subclass names its model, turns a row into model attributes (resolving codes of other masters with lookup())
 * and declares its fields, format and examples.
 */
abstract class MasterImports extends RowImports
{
    /** @var array<int, array<string, mixed>> import id => lookups loaded once for a run */
    private array $runContext = [];

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /**
     * The model attributes a row sets, without its code; leave out what a blank cell would clear. Problems go into
     * $errors (they become the row's errors).
     *
     * @param  array<string, string>  $r
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    abstract protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array;

    /** The row field that holds the code. */
    protected function keyField(): string
    {
        return 'code';
    }

    /** The model column the code is stored in. */
    protected function keyColumn(): string
    {
        return 'code';
    }

    /** Attributes a new record starts with (active from today, when the model has those columns). */
    protected function createDefaults(): array
    {
        $model = $this->model();
        $fillable = (new $model)->getFillable();

        return array_filter(['status' => in_array('status', $fillable, true) ? 'active' : null, 'effective_from' => in_array('effective_from', $fillable, true) ? now()->toDateString() : null]);
    }

    /** Fields a new record needs (an update needs only the code). Defaults to the required fields. */
    protected function requiredToCreate(): array
    {
        return array_keys(array_filter(static::fields(), fn (array $def) => $def[1]));
    }

    /** The records the code is looked up in: the whole organisation, whatever the uploader's access scope. */
    protected function query(): Builder
    {
        return ($this->model())::query()->withoutGlobalScope(AccessScope::class);
    }

    protected function entityType(): string
    {
        return $this->model();
    }

    protected function find(string $code): ?Model
    {
        return $this->query()->whereRaw('lower('.$this->keyColumn().') = ?', [mb_strtolower($code)])->first();
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $fields = static::fields();
        $code = trim((string) ($r[$this->keyField()] ?? ''));
        if ($code === '') {
            return ['action' => 'error', 'errors' => [($fields[$this->keyField()][0] ?? 'Code').' is required.']];
        }
        $key = mb_strtolower($code);
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["The same code is in row {$context['seen'][$key]}."]];
        }
        $context['seen'][$key] = $row->row_number;

        $existing = $this->find($code);
        // A record not on file in an "existing records only" upload is skipped (RowImports::validate), never an error.
        if ($existing === null && ! ImportMode::of($import)->adds()) {
            return ['action' => 'create'];
        }
        $errors = [];
        if ($existing === null) {
            foreach ($this->requiredToCreate() as $field) {
                if ($field !== $this->keyField() && ($r[$field] ?? '') === '') {
                    $errors[] = ($fields[$field][0] ?? $field).' is required for a new record.';
                }
            }
        }
        $attributes = $this->attributes($r, $context, $existing, $errors);
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => array_values(array_unique($errors))];
        }
        if ($existing === null) {
            return ['action' => 'create', 'match' => null];
        }
        $changed = array_keys(array_filter($attributes, fn ($value, $column) => ! $this->same($existing, $column, $value), ARRAY_FILTER_USE_BOTH));

        return $changed === []
            ? ['action' => 'skip', 'match' => ['id' => $existing->getKey(), 'reason' => 'Already on file with these values.']]
            : ['action' => 'update', 'match' => ['id' => $existing->getKey(), 'changes' => $changed]];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $context = $this->runContext[(int) $import->getKey()] ??= $this->prepare($import);
        $code = trim((string) $r[$this->keyField()]);
        $existing = $this->find($code);
        $errors = [];
        $attributes = $this->attributes($r, $context, $existing, $errors);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
        $reason = $this->label()." #{$import->getKey()} ({$import->original_name})";

        if ($existing === null) {
            $model = $this->model();
            $record = new $model(array_merge($this->createDefaults(), $attributes, [$this->keyColumn() => $code]));
            $record->withAuditReason($reason)->save();
            $this->created($record, $r, $context);

            return "Created {$code}";
        }

        $changes = app(ConfigurationChanges::class);
        if ($changes->isGoverned($existing) && $changes->requiresApproval($existing)) {
            $change = $changes->propose($existing, $attributes, $reason);

            return "Change #{$change->getKey()} for {$code} waits for approval in the Configuration Change Centre";
        }
        $existing->withAuditReason($reason)->update($attributes);
        $this->updated($existing, $r, $context);

        return "Updated {$code}";
    }

    /** After a record is created from a row (e.g. to link it to others). */
    protected function created(Model $record, array $r, array $context): void {}

    /** After a record is updated from a row. */
    protected function updated(Model $record, array $r, array $context): void {}

    /**
     * The id of another master by its code (case-insensitive), or an error naming the column. Blank gives null.
     *
     * @param  class-string<Model>  $class
     * @param  list<string>  $errors
     */
    protected function lookup(array &$context, string $class, ?string $code, string $what, array &$errors, string $column = 'code'): ?int
    {
        if ($code === null || trim($code) === '') {
            return null;
        }
        $context['codes'][$class.'|'.$column] ??= $class::query()->withoutGlobalScope(AccessScope::class)->pluck('id', $column)
            ->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all();
        $id = $context['codes'][$class.'|'.$column][mb_strtolower(trim($code))] ?? null;
        if ($id === null) {
            $errors[] = "{$what} '{$code}' does not exist.";
        }

        return $id;
    }

    /** active / inactive (also yes / no, 1 / 0); blank gives null. @param list<string> $errors */
    protected function status(?string $value, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = mb_strtolower(trim($value));
        if (in_array($v, ['active', 'yes', 'y', '1', 'true'], true)) {
            return 'active';
        }
        if (in_array($v, ['inactive', 'no', 'n', '0', 'false'], true)) {
            return 'inactive';
        }
        $errors[] = "Status '{$value}' must be active or inactive.";

        return null;
    }

    /** yes / no as a boolean; blank gives null. @param list<string> $errors */
    protected function flag(?string $value, string $what, array &$errors): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = mb_strtolower(trim($value));
        if (in_array($v, ['yes', 'y', '1', 'true'], true)) {
            return true;
        }
        if (in_array($v, ['no', 'n', '0', 'false'], true)) {
            return false;
        }
        $errors[] = "{$what} must be yes or no.";

        return null;
    }

    /** Whether the record already holds this value (compares stored values; dates by day). */
    private function same(Model $record, string $column, mixed $value): bool
    {
        $stored = $record->getRawOriginal($column);
        if ($value === null) {
            return $stored === null;
        }
        if (is_bool($value)) {
            return (bool) $stored === $value;
        }
        if (is_array($value)) {
            return json_decode((string) $stored, true) == $value;
        }
        $value = $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
        $stored = (string) $stored;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && str_starts_with($stored, $value)) {
            return true;
        }

        return is_numeric($value) && is_numeric($stored) ? (float) $value === (float) $stored : $stored === $value;
    }
}
