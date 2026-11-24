<?php

namespace App\Domain\Configuration\Overrides;

/**
 * Reads and writes one value inside nested settings by path, for field-level overrides. Segments are separated by dots;
 * a segment `name[key=value]` picks the row of the list `name` whose `key` equals `value` (a leave policy's entitlement
 * row for one leave type: `entitlements[leave_type_code=AL].days`). Rows are matched by key, never by position, so an
 * override keeps pointing at the same row when rows are added or reordered in the shared configuration.
 */
final class SettingsPath
{
    /** @return list<array{0: string, 1: ?string, 2: ?string}> name, row key, row value */
    public static function segments(string $path): array
    {
        preg_match_all('/([^.\[\]]+)(?:\[([^=\]]+)=([^\]]*)\])?/', $path, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => [$m[1], ($m[2] ?? '') !== '' ? $m[2] : null, ($m[2] ?? '') !== '' ? ($m[3] ?? '') : null], $matches);
    }

    public static function valid(string $path): bool
    {
        return $path !== '' && strlen($path) <= 191 && preg_match('/^([^.\[\]]+(\[[^=\]]+=[^\]]*\])?)(\.[^.\[\]]+(\[[^=\]]+=[^\]]*\])?)*$/', $path) === 1;
    }

    /** Whether the path exists in the data (a row picked by key must exist). */
    public static function has(array $data, string $path): bool
    {
        $missing = new \stdClass;

        return self::get($data, $path, $missing) !== $missing;
    }

    public static function get(array $data, string $path, mixed $default = null): mixed
    {
        $current = $data;
        foreach (self::segments($path) as [$name, $key, $value]) {
            if (! is_array($current) || ! array_key_exists($name, $current)) {
                return $default;
            }
            $current = $current[$name];
            if ($key !== null) {
                $index = self::rowIndex($current, $key, $value);
                if ($index === null) {
                    return $default;
                }
                $current = $current[$index];
            }
        }

        return $current;
    }

    /** Sets the value; a row picked by key that does not exist leaves the data unchanged (the row was removed). */
    public static function set(array $data, string $path, mixed $value): array
    {
        return self::write($data, self::segments($path), $value);
    }

    /** @param  list<array{0: string, 1: ?string, 2: ?string}>  $segments */
    private static function write(array $data, array $segments, mixed $value): array
    {
        [$name, $key, $match] = array_shift($segments);
        if ($key === null) {
            $data[$name] = $segments === [] ? $value : self::write(is_array($data[$name] ?? null) ? $data[$name] : [], $segments, $value);

            return $data;
        }
        $rows = is_array($data[$name] ?? null) ? $data[$name] : null;
        $index = $rows === null ? null : self::rowIndex($rows, $key, $match);
        if ($index === null) {
            return $data;
        }
        $rows[$index] = $segments === [] ? $value : self::write(is_array($rows[$index]) ? $rows[$index] : [], $segments, $value);
        $data[$name] = $rows;

        return $data;
    }

    private static function rowIndex(mixed $rows, string $key, ?string $value): int|string|null
    {
        if (! is_array($rows)) {
            return null;
        }
        foreach ($rows as $index => $row) {
            if (is_array($row) && array_key_exists($key, $row) && (string) $row[$key] === (string) $value) {
                return $index;
            }
        }

        return null;
    }
}
