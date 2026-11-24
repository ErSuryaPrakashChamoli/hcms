<?php

namespace App\Domain\Organisation\Resolution\Index;

use Illuminate\Support\Facades\Schema;

/** A table's columns, read once per process (the schema does not change while a request or job runs). */
final class Columns
{
    /** @var array<string, array<string, true>> */
    private static array $tables = [];

    public static function has(string $table, string $column): bool
    {
        self::$tables[$table] ??= array_fill_keys(Schema::getColumnListing($table), true);

        return isset(self::$tables[$table][$column]);
    }

    /**
     * @param  list<string|null>  $columns
     * @return list<string> the ones the table has, in order, without duplicates
     */
    public static function existing(string $table, array $columns): array
    {
        return array_values(array_unique(array_filter($columns, fn (?string $c) => $c !== null && self::has($table, $c))));
    }
}
