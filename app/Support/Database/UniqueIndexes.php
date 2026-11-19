<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 2026-11-19: the unique indexes of a table, read from the database itself (once per process), so a check made
 * before a save (UniqueCode) and the message made after a refused one (DuplicateEntry) agree with exactly what the
 * database enforces — including rows other checks would hide (archived, outside the viewer's access scope).
 */
final class UniqueIndexes
{
    /** @var array<string, array<string, list<string>>> "connection|database|table" => index name (lower case) => columns */
    private static array $memo = [];

    /** @return array<string, list<string>> index name (lower case) => its columns, primary key left out */
    public static function of(?string $connection, string $table): array
    {
        try {
            $db = DB::connection($connection);
            $key = $db->getName().'|'.$db->getDatabaseName().'|'.$table;
        } catch (Throwable) {
            return [];
        }

        return self::$memo[$key] ??= self::read($connection, $table);
    }

    /** @return list<string>|null the columns of one unique index, or null when the table has no such index */
    public static function columns(?string $connection, string $table, string $index): ?array
    {
        return self::of($connection, $table)[strtolower($index)] ?? null;
    }

    /** @return array<string, list<string>> */
    private static function read(?string $connection, string $table): array
    {
        try {
            $indexes = Schema::connection($connection)->getIndexes($table);
        } catch (Throwable) {
            return [];
        }

        $unique = [];
        foreach ($indexes as $index) {
            if (($index['unique'] ?? false) && ! ($index['primary'] ?? false)) {
                $unique[strtolower((string) $index['name'])] = array_values(array_map('strval', (array) $index['columns']));
            }
        }

        return $unique;
    }
}
