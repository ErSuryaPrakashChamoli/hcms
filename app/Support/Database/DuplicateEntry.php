<?php

namespace App\Support\Database;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * 2026-11-19: what a unique-index refusal was about — the table, the index's columns and the values that clashed —
 * read from the exception (MySQL/MariaDB, SQLite and PostgreSQL wordings), so the screen can name the field to
 * change instead of showing the database error.
 */
final class DuplicateEntry
{
    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $values  column => the value that was being written (when known)
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly array $values = [],
    ) {}

    public static function from(UniqueConstraintViolationException $e): ?self
    {
        $table = self::table($e->getSql());
        if ($table === null) {
            return null;
        }

        $columns = self::columns($e, $table);
        if ($columns === []) {
            return null;
        }

        $written = self::written($e->getSql(), $e->getBindings());
        $values = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $written)) {
                $values[$column] = $written[$column];
            }
        }

        return new self($table, $columns, $values);
    }

    /** @return list<string> the columns a person chooses (the tenant key is implied, never typed) */
    public function fields(): array
    {
        return array_values(array_diff($this->columns, ['tenant_id']));
    }

    /** "department", "cost centre" */
    public function noun(): string
    {
        return Str::singular(str_replace('_', ' ', $this->table));
    }

    /** "code", "employee" (for employee_id) */
    public static function fieldLabel(string $column): string
    {
        return str_replace('_', ' ', (string) preg_replace('/_id$/', '', $column));
    }

    private static function table(string $sql): ?string
    {
        if (preg_match('/^\s*(?:insert(?:\s+ignore)?\s+into|update(?:\s+ignore)?|replace\s+into)\s+[`"\[]?([\w.]+?)[`"\]]?[\s(]/i', $sql, $m) !== 1) {
            return null;
        }

        return str_contains($m[1], '.') ? Str::afterLast($m[1], '.') : $m[1];
    }

    /** @return list<string> */
    private static function columns(UniqueConstraintViolationException $e, string $table): array
    {
        $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();

        // SQLite: "UNIQUE constraint failed: departments.tenant_id, departments.code"
        if (preg_match('/UNIQUE constraint failed: ([^\n(]+)/i', $message, $m) === 1) {
            return array_values(array_map(fn (string $c) => Str::afterLast(trim($c), '.'), explode(',', trim($m[1]))));
        }

        // PostgreSQL: "DETAIL:  Key (tenant_id, code)=(2, DM) already exists."
        if (preg_match('/Key \(([^)]+)\)=/', $message, $m) === 1) {
            return array_values(array_map(fn (string $c) => trim($c, " \"\t"), explode(',', $m[1])));
        }

        // MySQL / MariaDB: "for key 'departments_tenant_id_code_unique'" (MySQL 8 prefixes "departments.");
        // PostgreSQL without detail: 'violates unique constraint "departments_tenant_id_code_unique"'.
        if (preg_match('/for key \'([^\']+)\'/i', $message, $m) === 1 || preg_match('/unique constraint "([^"]+)"/i', $message, $m) === 1) {
            $index = str_starts_with($m[1], $table.'.') ? Str::after($m[1], $table.'.') : $m[1];

            return UniqueIndexes::columns($e->getConnectionName(), $table, $index) ?? [];
        }

        return [];
    }

    /**
     * The column => value pairs of a single-row insert or an update, from the statement and its bindings.
     *
     * @param  array<int, mixed>  $bindings
     * @return array<string, mixed>
     */
    private static function written(string $sql, array $bindings): array
    {
        $bindings = array_values($bindings);

        if (preg_match('/^\s*insert(?:\s+ignore)?\s+into\s+\S+\s*\(([^)]*)\)\s*values\s*\(([^)]*)\)\s*$/is', $sql, $m) === 1) {
            $columns = array_map(fn (string $c) => trim($c, " `\"[]\t\n"), explode(',', $m[1]));

            return count($columns) === count($bindings) ? array_combine($columns, $bindings) : [];
        }

        if (preg_match('/^\s*update\s+\S+\s+set\s+(.+?)\s+where\s/is', $sql, $m) === 1) {
            $written = [];
            foreach (explode(',', $m[1]) as $i => $assignment) {
                if (! str_contains($assignment, '?') || ! array_key_exists($i, $bindings)) {
                    return $written;
                }
                $written[trim(Str::before($assignment, '='), " `\"[]\t\n")] = $bindings[$i];
            }

            return $written;
        }

        return [];
    }
}
