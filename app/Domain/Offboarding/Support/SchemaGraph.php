<?php

namespace App\Domain\Offboarding\Support;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * SaaS.9: the foreign keys of the application's own database (never another schema on the same server), read from the
 * schema itself, so a deletion follows the real constraints, not a hand-kept list.
 *
 * Used to (1) protect data: a table whose rows are still referenced by kept records is not deleted; (2) order a deletion
 * with constraint checks ON: nullable references inside the set being deleted are cleared first (that breaks the
 * version / current-version cycles and the "corrects" / "supersedes" self-references), then tables are deleted children
 * first; (3) verify afterwards that no reference into a deleted table is left dangling.
 */
final class SchemaGraph
{
    /** @var list<array{child: string, column: string, parent: string, parent_column: string, on_delete: string, nullable: bool}>|null */
    private static ?array $edges = null;

    /** @return list<array{child: string, column: string, parent: string, parent_column: string, on_delete: string, nullable: bool}> */
    public static function edges(): array
    {
        if (self::$edges !== null) {
            return self::$edges;
        }
        $edges = [];
        foreach (self::tables() as $table) {
            $nullable = [];
            foreach (Schema::getColumns($table) as $column) {
                $nullable[$column['name']] = (bool) $column['nullable'];
            }
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (count($fk['columns']) !== 1) {
                    throw new RuntimeException("Composite foreign key on {$table} is not supported by the deletion planner.");
                }
                $edges[] = ['child' => $table, 'column' => $fk['columns'][0], 'parent' => $fk['foreign_table'], 'parent_column' => $fk['foreign_columns'][0],
                    'on_delete' => strtolower((string) $fk['on_delete']), 'nullable' => $nullable[$fk['columns'][0]] ?? false];
            }
        }

        return self::$edges = $edges;
    }

    /** Clears the cache (tests and long-running workers after a migration). */
    public static function flush(): void
    {
        self::$edges = null;
    }

    /** @return list<string> every table the inventory knows (tenant tables, children, platform tables) */
    public static function tables(): array
    {
        return array_values(array_unique([...array_keys(DataInventory::tableCategories()), ...array_keys(DataInventory::CHILDREN), ...DataInventory::PLATFORM]));
    }

    /**
     * The deletion order of a set of tables, children first, with constraint checks on: EVERY reference between two tables
     * of the set orders the referencing table first, so deleting a parent never cascades into, nulls or is blocked by a
     * row that is itself still to be deleted (a set-null would otherwise rewrite rows, e.g. the audit chain's actors,
     * before their own deletion). The only references that do not order are the nullable ones inside a cycle (a table
     * and its current-version pointer, a "corrects" / "supersedes" self-reference): those are cleared first (nullify()).
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public static function deletionOrder(array $tables): array
    {
        [$blocking] = self::analyse($tables);
        $order = [];
        $state = [];
        $visit = function (string $table, array $path) use (&$visit, &$order, &$state, $blocking): void {
            if (($state[$table] ?? null) === 'done') {
                return;
            }
            if (($state[$table] ?? null) === 'visiting') {
                throw new RuntimeException('Deletion order has a non-nullable cycle: '.implode(' → ', [...$path, $table]));
            }
            $state[$table] = 'visiting';
            foreach (array_keys($blocking[$table] ?? []) as $child) {
                $visit($child, [...$path, $table]);
            }
            $state[$table] = 'done';
            $order[] = $table;
        };
        sort($tables);
        foreach ($tables as $table) {
            $visit($table, []);
        }

        return $order;
    }

    /**
     * The references cleared before the deletes: the nullable ones inside a cycle of the set, and nullable self-references
     * that restrict (a batch could otherwise delete a row another row of the same table still points to). Every other
     * reference orders the deletion instead (deletionOrder()).
     *
     * @param  list<string>  $tables
     * @return list<array{child: string, column: string}>
     */
    public static function nullify(array $tables): array
    {
        [, $nullify] = self::analyse($tables);
        ksort($nullify);

        return array_values($nullify);
    }

    /**
     * @param  list<string>  $tables
     * @return array{0: array<string, array<string, true>>, 1: array<string, array{child: string, column: string}>} [parent => children first, cleared references]
     */
    private static function analyse(array $tables): array
    {
        $set = array_flip($tables);
        $edges = array_values(array_filter(self::edges(), fn (array $e) => isset($set[$e['child']], $set[$e['parent']])));
        $nullify = [];
        foreach ($edges as $e) {
            if ($e['child'] === $e['parent'] && $e['nullable'] && in_array($e['on_delete'], ['restrict', 'no action'], true)) {
                $nullify[$e['child'].'.'.$e['column']] = ['child' => $e['child'], 'column' => $e['column']];
            }
        }
        // Strongly connected components of "parent must wait for child" (Tarjan).
        $graph = [];
        foreach ($edges as $e) {
            if ($e['child'] !== $e['parent']) {
                $graph[$e['parent']][$e['child']] = true;
            }
        }
        $component = self::components(array_keys($set), $graph);
        $blocking = [];
        foreach ($edges as $e) {
            if ($e['child'] === $e['parent']) {
                continue;
            }
            if ($e['nullable'] && $component[$e['child']] === $component[$e['parent']]) {
                $nullify[$e['child'].'.'.$e['column']] = ['child' => $e['child'], 'column' => $e['column']];

                continue;
            }
            $blocking[$e['parent']][$e['child']] = true;
        }

        return [$blocking, $nullify];
    }

    /**
     * @param  list<string>  $nodes
     * @param  array<string, array<string, true>>  $graph
     * @return array<string, int> node => component id
     */
    private static function components(array $nodes, array $graph): array
    {
        $index = 0;
        $stack = [];
        $on = [];
        $indexes = [];
        $low = [];
        $component = [];
        $count = 0;
        $connect = function (string $v) use (&$connect, &$index, &$stack, &$on, &$indexes, &$low, &$component, &$count, $graph): void {
            $indexes[$v] = $low[$v] = $index++;
            $stack[] = $v;
            $on[$v] = true;
            foreach (array_keys($graph[$v] ?? []) as $w) {
                if (! isset($indexes[$w])) {
                    $connect($w);
                    $low[$v] = min($low[$v], $low[$w]);
                } elseif ($on[$w] ?? false) {
                    $low[$v] = min($low[$v], $indexes[$w]);
                }
            }
            if ($low[$v] === $indexes[$v]) {
                do {
                    $w = array_pop($stack);
                    $on[$w] = false;
                    $component[$w] = $count;
                } while ($w !== $v);
                $count++;
            }
        };
        sort($nodes);
        foreach ($nodes as $node) {
            if (! isset($indexes[$node])) {
                $connect($node);
            }
        }

        return $component;
    }
}
