<?php

namespace App\Support\Masters;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Master-relationships R1: what uses a master record, so a delete is refused with counts instead of
 * hitting the database's foreign-key constraint (an error page) or leaving a dangling structure node.
 *
 * A use is
 * - a row of another table whose single-column foreign key points at the record, whatever the key does on
 *   delete: RESTRICT would answer with an error page, SET NULL would silently unlink, and CASCADE would
 *   silently delete history (a company's payroll runs, a skill's person skills). Current and past employee
 *   positions, positions and other masters' links are all uses;
 * - the record's place in the organisation structure (organisation_nodes is polymorphic, so no key).
 *
 * The only exceptions are the record's own parts (OWN_PARTS), which go with it. The foreign keys are read from
 * the database once per process, so a new table that points at a master is covered without code. Only counts
 * leave this class: never another record's name, so a refusal cannot show anything outside the caller's tenant.
 */
final class MasterUsage
{
    /** Phrases for the tables people meet; any other table reads as its own name. */
    private const NOUNS = [
        'employee_positions' => ['employee position', ' (current or past)'],
        'position_versions' => ['position version', ''],
    ];

    /** Keys that point at the record's own parts: they are deleted with it and are not uses (table.column). */
    private const OWN_PARTS = [
        'designation_employment_type.designation_id',   // a designation's own employment-type links
    ];

    /**
     * Own parts that are records of their own: a company's legal entities and establishments are created with it
     * (LegalEntities::ensureFor) and deleted with it by the database. They are not uses of the company, but whatever
     * uses them is (a challan, an establishment assignment, a registration …), so a company is deletable only while
     * nothing uses it or its parts. referenced table => [part table => key column to the record].
     */
    private const COMPOSITE_PARTS = [
        'companies' => ['legal_entities' => 'company_id', 'establishments' => 'company_id'],
    ];

    /** @var array<string, list<array{table: string, column: string}>> referenced table => keys that make a use */
    private static array $references = [];

    /** @return list<array{table: string, column: string, count: int, label: string}> each use with its count, largest first */
    public function of(Model $record): array
    {
        $connection = $record->getConnection();
        $table = $record->getTable();
        $id = $record->getKey();
        if ($id === null) {
            return [];
        }

        $uses = [];
        $parts = self::COMPOSITE_PARTS[$table] ?? [];
        foreach ($this->referencesTo($connection, $table) as ['table' => $from, 'column' => $column]) {
            if (($parts[$from] ?? null) === $column) {
                continue;
            }
            $count = $connection->table($from)->where($column, $id)->count();
            if ($count > 0) {
                $uses[] = ['table' => $from, 'column' => $column, 'count' => $count, 'label' => self::label($from, $column, $table, $count)];
            }
        }
        // What uses the record's own parts, except the links between those parts (an establishment's legal entity).
        $partIds = array_map(fn (string $key, string $partTable) => [$partTable, $connection->table($partTable)->where($key, $id)->pluck('id')->all()], $parts, array_keys($parts));
        $partIds = array_column($partIds, 1, 0);
        foreach ($partIds as $partTable => $ids) {
            if ($ids === []) {
                continue;
            }
            foreach ($this->referencesTo($connection, $partTable) as ['table' => $from, 'column' => $column]) {
                if (isset($partIds[$from]) || $from === $table) {
                    continue;
                }
                $count = $connection->table($from)->whereIn($column, $ids)->count();
                if ($count > 0) {
                    $uses[] = ['table' => $from, 'column' => $column, 'count' => $count,
                        'label' => self::label($from, $column, $partTable, $count).' of its '.str_replace('_', ' ', $partTable)];
                }
            }
        }
        if (Schema::connection($connection->getName())->hasTable('organisation_nodes')) {
            $placed = $connection->table('organisation_nodes')->where('nodeable_type', $record->getMorphClass())->where('nodeable_id', $id)->count();
            if ($placed > 0) {
                $uses[] = ['table' => 'organisation_nodes', 'column' => 'nodeable_id', 'count' => $placed, 'label' => 'a place in the organisation structure'];
            }
        }
        usort($uses, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $uses;
    }

    public function inUse(Model $record): bool
    {
        return $this->of($record) !== [];
    }

    /** Refuse the delete of a record something still uses. */
    public function assertDeletable(Model $record): void
    {
        $uses = $this->of($record);
        if ($uses !== []) {
            throw new MasterInUseException(self::explain($record, $uses), $uses);
        }
    }

    /** Whether the record can be switched off instead of deleted (it has an active / inactive status). */
    public static function canDeactivate(Model $record): bool
    {
        return ActiveMasters::hasStatus($record::class);
    }

    public static function isActive(Model $record): bool
    {
        // A record created in this request may not carry its database default yet.
        $status = array_key_exists('status', $record->getAttributes())
            ? $record->getAttribute('status')
            : $record->newQuery()->whereKey($record->getKey())->value('status');

        return ($status instanceof \BackedEnum ? $status->value : $status) === 'active';
    }

    /**
     * "Sourcing Manager is in use, so it cannot be deleted: 37 employee positions (current or past) and 2 positions use it.
     * Deactivate it instead: …"
     *
     * @param  list<array{label: string}>  $uses
     */
    public static function explain(Model $record, array $uses): string
    {
        $name = self::name($record);
        $others = array_values(array_filter($uses, fn (array $u) => $u['table'] !== 'organisation_nodes'));
        $parts = [];
        if ($others !== []) {
            $parts[] = self::sentence(array_column($others, 'label')).' '.(count($others) === 1 && $others[0]['count'] === 1 ? 'uses' : 'use').' it';
        }
        if (count($others) < count($uses)) {
            $parts[] = 'it has a place in the organisation structure';
        }
        $why = implode(', and ', $parts);
        $next = match (true) {
            self::canDeactivate($record) && self::isActive($record) => ' Deactivate it instead: it leaves the pickers for new choices, and every record and the history that use it still show it.',
            self::canDeactivate($record) => ' It is already inactive, so it no longer appears in pickers.',
            default => '',
        };
        $tree = collect($uses)->contains('table', 'organisation_nodes') ? ' To delete it, remove it from the organisation structure in the Organisation Designer and clear every other use first.' : '';

        return "{$name} is in use, so it cannot be deleted: {$why}.{$next}{$tree}";
    }

    /** Forget the foreign keys read so far (after a migration in the same process). */
    public static function flush(): void
    {
        self::$references = [];
    }

    /** @return list<array{table: string, column: string}> */
    private function referencesTo(Connection $connection, string $table): array
    {
        $key = $connection->getName().'.'.$table;

        return self::$references[$key] ??= $this->discover($connection, $table);
    }

    /** @return list<array{table: string, column: string}> */
    private function discover(Connection $connection, string $table): array
    {
        $prefixed = $connection->getTablePrefix().$table;
        $prefix = $connection->getTablePrefix();
        $keys = [];

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // One query instead of one per table.
            $rows = $connection->select(
                'select k.TABLE_NAME as source, k.COLUMN_NAME as source_column, r.DELETE_RULE as rule,
                    (select count(*) from information_schema.KEY_COLUMN_USAGE c where c.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA and c.TABLE_NAME = k.TABLE_NAME and c.CONSTRAINT_NAME = k.CONSTRAINT_NAME) as width
                from information_schema.KEY_COLUMN_USAGE k
                join information_schema.REFERENTIAL_CONSTRAINTS r on r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA and r.CONSTRAINT_NAME = k.CONSTRAINT_NAME and r.TABLE_NAME = k.TABLE_NAME
                where k.REFERENCED_TABLE_SCHEMA = database() and k.REFERENCED_TABLE_NAME = ?',
                [$prefixed],
            );
            foreach ($rows as $row) {
                $keys[] = ['table' => (string) $row->source, 'column' => (string) $row->source_column, 'on_delete' => strtolower((string) $row->rule), 'width' => (int) $row->width];
            }
        } else {
            $schema = Schema::connection($connection->getName());
            foreach ($schema->getTables() as $candidate) {
                foreach ($schema->getForeignKeys($candidate['name']) as $foreign) {
                    if ($foreign['foreign_table'] === $prefixed) {
                        $keys[] = ['table' => $candidate['name'], 'column' => (string) ($foreign['columns'][0] ?? ''), 'on_delete' => (string) $foreign['on_delete'], 'width' => count($foreign['columns'])];
                    }
                }
            }
        }

        return collect($keys)
            ->filter(fn (array $k) => $k['width'] === 1 && $k['column'] !== '')
            ->map(fn (array $k) => ['table' => $prefix !== '' && str_starts_with($k['table'], $prefix) ? substr($k['table'], strlen($prefix)) : $k['table'], 'column' => $k['column']])
            ->reject(fn (array $k) => in_array($k['table'].'.'.$k['column'], self::OWN_PARTS, true))
            ->unique(fn (array $k) => $k['table'].'.'.$k['column'])
            ->sortBy(fn (array $k) => $k['table'].'.'.$k['column'])
            ->values()->all();
    }

    /** "37 employee positions (current or past)", "3 designations (default reporting level)", "1 sub-department". */
    private static function label(string $from, string $column, string $referenced, int $count): string
    {
        if ($from === $referenced) {
            $noun = match ($column) {
                'parent_department_id' => 'sub-department',
                'parent_location_id' => 'sub-branch',
                default => 'child '.Str::singular(str_replace('_', ' ', $referenced)),
            };

            return $count.' '.Str::plural($noun, $count);
        }
        [$noun, $suffix] = self::NOUNS[$from] ?? [Str::singular(str_replace('_', ' ', $from)), ''];
        $conventional = Str::singular($referenced).'_id';
        if ($suffix === '' && $column !== $conventional) {
            $suffix = ' ('.trim(str_replace('_', ' ', Str::beforeLast($column, '_id'))).')';
        }

        return $count.' '.Str::plural($noun, $count).$suffix;
    }

    /** @param  list<string>  $parts */
    private static function sentence(array $parts): string
    {
        return count($parts) <= 1 ? (string) ($parts[0] ?? '') : implode(', ', array_slice($parts, 0, -1)).' and '.end($parts);
    }

    private static function name(Model $record): string
    {
        foreach (['name', 'legal_name', 'title', 'code'] as $attribute) {
            if (filled($record->getAttribute($attribute))) {
                return '“'.$record->getAttribute($attribute).'”';
            }
        }

        return 'This record';
    }
}
