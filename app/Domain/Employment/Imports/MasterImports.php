<?php

namespace App\Domain\Employment\Imports;

use App\Domain\Configuration\Services\ConfigurationChanges;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\Workbooks\ImportReferences;
use App\Domain\Employment\Imports\Workbooks\PlannedReferences;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Resolution\NameKey;
use App\Domain\Organisation\Resolution\ReferenceMappings;
use App\Domain\Organisation\Resolution\ResolutionStatus;
use App\Support\Imports\ClearValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Data import: a master list (branches, departments, grades, vendors, asset types …). A row whose code is on file
 * updates that record and any other row creates one; a code may appear once per file. Names first (2026-11-26): the
 * code may be left blank — the row then updates the record of that name, or adds one with a code made from the name
 * (keyed()); references to other records take their names (lookup()). Blank cells keep what is on file. Creates are audited by the model. An update of a governed configuration record goes through the
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

    /**
     * Names first: the row field holding the record's name. With one, the code may be left blank (keyed()); null keeps
     * the code required (places whose codes are official, e.g. states).
     */
    protected function nameField(): ?string
    {
        return array_key_exists('name', static::fields()) ? 'name' : null;
    }

    /** The model column the name is stored in. */
    protected function nameColumn(): string
    {
        return 'name';
    }

    /**
     * The column of the company a record belongs to, when two companies may each have a record of the same name (a
     * department "Finance" in each). Null: names are matched across the whole organisation.
     */
    protected function nameOwnerColumn(): ?string
    {
        $model = $this->model();

        return isset(static::fields()['company_code']) && in_array('company_id', (new $model)->getFillable(), true) ? 'company_id' : null;
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
        $this->loadKeys($context);
        $id = $context['keys'][mb_strtolower(trim($code))] ?? null;

        return $id === null ? null : $this->query()->find($id);
    }

    private function loadKeys(array &$context): void
    {
        $context['keys'] ??= $this->query()->toBase()->pluck('id', $this->keyColumn())
            ->mapWithKeys(fn ($id, $key) => [mb_strtolower(trim((string) $key)) => (int) $id])->all();
    }

    /**
     * Names first: a row that leaves the code blank gets one here, before anything else reads the row. The record of
     * that name (for the row's company, when records belong to companies) gives its code, so the row updates it; when no
     * record has the name, a new code is made from it (ReferenceMappings::codeFrom: "Sales & Marketing" → SALES_AND_MARKETING,
     * _2, _3 … when taken). Several records of that name are refused: the code says which one. Two rows of a file may not
     * add the same name. Returns the row with its code, and the problem (null when there is none).
     *
     * @param  array<string, string>  $r
     * @return array{0: array<string, string>, 1: ?string}
     */
    protected function keyed(array $r, array &$context, EmployeeImportRow $row): array
    {
        $field = $this->keyField();
        $nameField = $this->nameField();
        if ($nameField === null) {
            return [$r, null];
        }
        $fields = static::fields();
        $code = trim((string) ($r[$field] ?? ''));
        $name = trim((string) ($r[$nameField] ?? ''));
        if ($code === '' && ($name === '' || ClearValue::isClear($name))) {
            return [$r, ($fields[$nameField][0] ?? 'Name').' is required (or give the '.mb_strtolower($fields[$field][0] ?? 'code').' of the record to change).'];
        }
        if ($name === '' || ClearValue::isClear($name)) {
            return [$r, null];
        }
        $owner = $this->nameOwnerColumn();
        $company = null;
        if ($owner !== null && filled($r['company_code'] ?? null) && ! ClearValue::isClear($r['company_code'])) {
            $ignored = [];
            $company = $this->lookup($context, Company::class, $r['company_code'], $fields['company_code'][0] ?? 'Company', $ignored);
            $company = $company !== null && $company > 0 ? $company : null;
        }
        $seen = NameKey::key($name).'|'.($company ?? '');
        if ($code !== '') {
            // A row with its code: remembered, so a later row naming the same record without a code is caught.
            $context['seen_names'][$seen] ??= ['row' => $row->row_number, 'code' => $code];

            return [$r, null];
        }
        if (isset($context['seen_names'][$seen])) {
            return [$r, "The same name is in row {$context['seen_names'][$seen]['row']}."];
        }

        $matches = $this->namesakes($context, NameKey::key($name), $owner, $company);
        if (count($matches) > 1) {
            $codes = implode(', ', array_map(fn (array $m) => (string) $m['code'], array_slice($matches, 0, 5)));

            return [$r, "Several {$this->plural()} are called '{$name}' ({$codes}): put the code of the one you mean in the ".($fields[$field][0] ?? 'Code').' column.'];
        }
        $code = $matches !== [] ? (string) $matches[0]['code'] : $this->newCode($context, $name);
        $context['seen_names'][$seen] = ['row' => $row->row_number, 'code' => $code];
        $r[$field] = $code;

        return [$r, null];
    }

    /**
     * Records of this name (for the company, when records belong to companies: that company's own, else one shared by
     * every company), the live one first; several only when the name is truly shared.
     *
     * @return list<array{id: int, code: ?string, owner: ?int, live: bool}>
     */
    private function namesakes(array &$context, string $nameKey, ?string $owner, ?int $company): array
    {
        if (! isset($context['names'])) {
            $model = new ($this->model());
            $columns = array_values(array_filter(['id', $this->keyColumn(), $this->nameColumn(), $owner,
                in_array('status', $model->getFillable(), true) ? 'status' : null]));
            $context['names'] = [];
            foreach ($this->query()->toBase()->select($columns)->cursor() as $record) {
                $this->rememberName($context, (array) $record, $owner);
            }
        }
        $all = $context['names'][$nameKey] ?? [];
        if ($owner !== null && $company !== null) {
            $own = array_values(array_filter($all, fn (array $m) => $m['owner'] === $company));
            $all = $own !== [] ? $own : array_values(array_filter($all, fn (array $m) => $m['owner'] === null));
        }
        $live = array_values(array_filter($all, fn (array $m) => $m['live']));

        return count($all) > 1 && count($live) === 1 ? $live : $all;
    }

    /** @param array<string, mixed> $record */
    private function rememberName(array &$context, array $record, ?string $owner): void
    {
        $key = NameKey::key((string) ($record[$this->nameColumn()] ?? ''));
        if ($key === '' || ! isset($context['names'])) {
            return;
        }
        $context['names'][$key][] = ['id' => (int) $record['id'], 'code' => isset($record[$this->keyColumn()]) ? (string) $record[$this->keyColumn()] : null,
            'owner' => $owner !== null && isset($record[$owner]) ? (int) $record[$owner] : null,
            'live' => ! in_array(mb_strtolower((string) ($record['status'] ?? 'active')), ['inactive', 'archived', 'closed'], true)];
    }

    /** A free code made from the name: not on file (deleted records included) and not used by an earlier row of the file. */
    private function newCode(array &$context, string $name): string
    {
        $this->loadKeys($context);
        $base = ReferenceMappings::codeFrom($name);
        $model = new ($this->model());
        $trashed = in_array(SoftDeletes::class, class_uses_recursive($model), true);
        for ($attempt = 1; ; $attempt++) {
            $code = $attempt === 1 ? $base : mb_substr($base, 0, 28).'_'.$attempt;
            $lower = mb_strtolower($code);
            if (isset($context['keys'][$lower]) || isset($context['seen'][$lower])) {
                continue;
            }
            if ($trashed && $this->query()->onlyTrashed()->whereRaw('lower('.$this->keyColumn().') = ?', [$lower])->exists()) {
                continue;
            }

            return $code;
        }
    }

    /** "departments", for messages. */
    private function plural(): string
    {
        return mb_strtolower(Str::before($this->label(), ' upload'));
    }

    /**
     * The record an earlier row of this file names (names first: a parent given by name, added without a code). Only
     * while a run adds rows in order, or while the file is checked (then -1: not on file yet).
     */
    protected function addedEarlier(array $context, string $name): ?int
    {
        $key = NameKey::key($name);
        foreach ($context['seen_names'] ?? [] as $seen => $entry) {
            if (str_starts_with($seen, $key.'|')) {
                return $context['keys'][mb_strtolower($entry['code'])] ?? self::PLANNED;
            }
        }

        return null;
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $fields = static::fields();
        [$r, $problem] = $this->keyed($r, $context, $row);
        if ($problem !== null) {
            return ['action' => 'error', 'errors' => [$problem]];
        }
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
        [$r, $problem] = $this->keyed($r, $context, $row);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
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
            $this->rememberName($context, $record->getAttributes(), $this->nameOwnerColumn());
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
