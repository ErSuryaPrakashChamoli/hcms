<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Configuration\Services\ConfigurationChanges;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\Workbooks\ImportReferences;
use App\Domain\Employment\Imports\Workbooks\PlannedReferences;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Resolution\ResolutionStatus;
use App\Support\Imports\ClearValue;
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
    /**
     * What lookup() / parentId() return besides an id: -1 = a record an earlier worksheet of the workbook adds (looked up
     * again when the row runs); -2 = `(none)`, the link is removed (null on save; refused for required columns).
     */
    public const PLANNED = -1;

    public const CLEARED = -2;

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

    /**
     * The record a code names, from a code → id map loaded once per check or run (`$context['keys']`) instead of one
     * `lower(code)` query per row: that predicate cannot use the code index, so each row scanned the whole list and a
     * large file took time growing with the square of its rows (10,000 departments: 151 s). Codes the run adds are put
     * in the map as they are created.
     */
    protected function existing(array &$context, string $code): ?Model
    {
        $context['keys'] ??= $this->query()->toBase()->pluck('id', $this->keyColumn())
            ->mapWithKeys(fn ($id, $key) => [mb_strtolower(trim((string) $key)) => (int) $id])->all();
        $id = $context['keys'][mb_strtolower(trim($code))] ?? null;

        return $id === null ? null : $this->query()->find($id);
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

        $existing = $this->existing($context, $code);
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
        // `(none)` removes an optional link; a required column (a branch's company, a sub department's parent) refuses it.
        foreach ($fields as $field => [$label, $required]) {
            if ($required && ClearValue::isClear($r[$field] ?? null)) {
                $errors[] = "{$label} is required here; ".ClearValue::NONE.' cannot be used.';
            }
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => array_values(array_unique($errors))];
        }
        $this->startRow($r, $context, $row, $import);
        $attributes = $this->attributes($r, $context, $existing, $errors);
        if ($errors === []) {
            $errors = $this->rowErrors($r, $attributes, $existing, $context, $import);
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => array_values(array_unique($errors))];
        }
        if ($existing === null) {
            return ['action' => 'create', 'match' => null];
        }
        $changed = array_keys(array_filter($attributes, fn ($value, $column) => ! $this->same($existing, $column, $value), ARRAY_FILTER_USE_BOTH));
        $removed = array_values(array_filter($changed, fn (string $column) => $attributes[$column] === self::CLEARED));
        $extra = $this->otherChanges($r, $existing, $context, $import);

        return $changed === [] && $extra === []
            ? ['action' => 'skip', 'match' => ['id' => $existing->getKey(), 'reason' => 'Already on file with these values.']]
            : ['action' => 'update', 'match' => array_filter(['id' => $existing->getKey(), 'changes' => [...$changed, ...$extra],
                'removed' => array_map(fn (string $column) => $this->linkLabel($column).': '.$this->storedLabel($existing, $column).' → (removed)', $removed)])];
    }

    /**
     * Errors beyond the row's own cells, once its attributes are known (e.g. where a record may apply). None by default.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    protected function rowErrors(array $r, array $attributes, ?Model $existing, array &$context, EmployeeImport $import): array
    {
        return [];
    }

    /**
     * Changes a row makes besides the record's columns (e.g. the companies it applies to), so an otherwise unchanged
     * row still counts as an update. None by default.
     *
     * @return list<string>
     */
    protected function otherChanges(array $r, Model $existing, array &$context, EmployeeImport $import): array
    {
        return [];
    }

    /** After a row's record is saved (created or updated), inside the row's transaction. Nothing by default. */
    protected function saved(Model $record, bool $created, array $r, array &$context, EmployeeImport $import): void {}

    /**
     * Before a row's references are looked up: where the row is (for the name review) and the company it names, if
     * any — records found for its other references must apply to that company (the applicability framework).
     */
    private function startRow(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): void
    {
        $context['row_ref'] = ['sheet' => $import->sheet, 'row' => (int) ($row->data[Workbooks\WorkbookImports::ROW_COLUMN] ?? $row->row_number + 1)];
        $context['row_company'] = null;
        $context['row_company_seen'] = [];
        $company = $r['company_code'] ?? null;
        if (isset(static::fields()['company_code']) && $company !== null && trim($company) !== '' && ! ClearValue::isClear($company)) {
            $ignored = [];
            $id = $this->lookup($context, Company::class, $company, static::fields()['company_code'][0], $ignored);
            if ($id !== null) {
                $context['row_company_seen'][mb_strtolower(trim($company))] = $id;
            }
            $context['row_company'] = $id !== null && $id > 0 ? $id : null;
        }
    }

    /** "Level" for level_id, for the removal shown in the preview. */
    private function linkLabel(string $column): string
    {
        $field = preg_replace('/_id$/', '_code', $column);

        return preg_replace('/ code$/i', '', static::fields()[$field][0] ?? static::fields()[$column][0] ?? ucfirst(str_replace('_', ' ', preg_replace('/_id$/', '', $column))));
    }

    /** The code (or id) a link holds now, for the preview. */
    private function storedLabel(Model $record, string $column): string
    {
        $id = $record->getRawOriginal($column);
        if ($id === null) {
            return '—';
        }
        $relation = lcfirst(str_replace('_', '', ucwords(preg_replace('/_id$/', '', $column), '_')));
        $related = method_exists($record, $relation) ? $record->{$relation}()->getRelated()->newQueryWithoutScope(AccessScope::class)->find($id) : null;

        return (string) ($related?->getAttribute('code') ?? $related?->getAttribute('name') ?? "#{$id}");
    }

    /** `(none)` becomes null just before the record is written. @param array<string, mixed> $attributes */
    private static function written(array $attributes): array
    {
        return array_map(fn ($value) => $value === self::CLEARED ? null : $value, $attributes);
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $this->runContext[(int) $import->getKey()] ??= $this->prepare($import);
        $context = &$this->runContext[(int) $import->getKey()];
        $code = trim((string) $r[$this->keyField()]);
        $existing = $this->existing($context, $code);
        $errors = [];
        $this->startRow($r, $context, $row, $import);
        $attributes = $this->attributes($r, $context, $existing, $errors);
        if ($errors === []) {
            $errors = $this->rowErrors($r, $attributes, $existing, $context, $import);
        }
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
        $reason = $this->label()." #{$import->getKey()} ({$import->original_name})";
        $removed = $existing === null ? [] : array_values(array_filter(array_keys($attributes),
            fn (string $column) => $attributes[$column] === self::CLEARED && $existing->getRawOriginal($column) !== null));
        $attributes = self::written($attributes);

        if ($existing === null) {
            $model = $this->model();
            $record = new $model(array_merge($this->createDefaults(), $attributes, [$this->keyColumn() => $code]));
            $record->withAuditReason($reason)->save();
            $context['keys'][mb_strtolower($code)] = (int) $record->getKey();
            $this->created($record, $r, $context);
            $this->saved($record, true, $r, $context, $import);

            return "Created {$code}";
        }

        $changes = app(ConfigurationChanges::class);
        if ($changes->isGoverned($existing) && $changes->requiresApproval($existing)) {
            $change = $changes->propose($existing, $attributes, $reason);

            return "Change #{$change->getKey()} for {$code} waits for approval in the Configuration Change Centre";
        }
        $existing->withAuditReason($reason)->update($attributes);
        $this->updated($existing, $r, $context);
        $this->saved($existing, false, $r, $context, $import);

        return "Updated {$code}".($removed === [] ? '' : ' (removed: '.implode(', ', array_map(fn ($c) => mb_strtolower($this->linkLabel($c)), $removed)).')');
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
        // `(none)`: remove the link (never looked up, never passed to the name resolver).
        if (ClearValue::isClear($code)) {
            return self::CLEARED;
        }
        // The row's company, already found by startRow(): reuse it (a name is resolved, and reported, once per row).
        if ($class === Company::class && $column === 'code' && isset($context['row_company_seen'][mb_strtolower(trim($code))])) {
            return $context['row_company_seen'][mb_strtolower(trim($code))];
        }
        $context['codes'][$class.'|'.$column] ??= $class::query()->withoutGlobalScope(AccessScope::class)->pluck('id', $column)
            ->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all();
        $id = $context['codes'][$class.'|'.$column][mb_strtolower(trim($code))] ?? null;
        $references = app(ImportReferences::class);
        $company = $class === Company::class ? null : ($context['row_company'] ?? null);
        if ($id !== null) {
            // The code matched exactly as it always did; a row that names a company may only use what applies to it.
            if ($company !== null && ! $references->appliesTo($class, $id, $company)) {
                $errors[] = "{$what} '{$code}' is not available to {$references->companyName($company)}. Extend where it applies, or use one that applies to that company.";

                return null;
            }

            return $id;
        }
        // Master uploads: a code an earlier worksheet of the workbook adds counts as there while the workbook is checked
        // (-1: not on file yet; the run looks it up again). One whose own row has errors says so.
        $planned = app(PlannedReferences::class);
        if ($planned->plannedRecord($class, $code) !== null) {
            return self::PLANNED;
        }
        if (($blocked = $planned->blockedMessage($class, $code, $what)) !== null) {
            $errors[] = $blocked;

            return null;
        }
        // Not a code: a name, "Name (CODE)" or a mapping confirmed in the review (only for column 'code' lookups).
        $resolution = $column === 'code' ? $references->resolve($class, $code, $company, $context['row_ref']['sheet'] ?? null, $context['row_ref']['row'] ?? null, $what) : null;
        if ($resolution !== null && $resolution->ok()) {
            return (int) $resolution->id;
        }
        $errors[] = $resolution !== null && $resolution->status !== ResolutionStatus::Unmatched && $resolution->error() !== null
            ? $resolution->error() : "{$what} '{$code}' does not exist.";

        return null;
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
        if ($value === null || $value === self::CLEARED) {
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
