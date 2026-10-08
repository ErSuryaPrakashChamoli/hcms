<?php

namespace App\Domain\Offboarding\Support;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * SaaS.9: which columns of each table are encrypted at rest (from the Eloquent models' casts), so an export can give the
 * customer their own data readable. Secrets are never decrypted: they are excluded before (DataInventory::SECRET_COLUMNS).
 */
final class TableModels
{
    /** @var array<string, array<string, string>>|null table => column => cast */
    private static ?array $encrypted = null;

    /** @return array<string, string> column => encrypted cast (encrypted, encrypted:array, ...) */
    public static function encryptedColumns(string $table): array
    {
        return (self::$encrypted ??= self::scan())[$table] ?? [];
    }

    /** @return array<string, array<string, string>> */
    private static function scan(): array
    {
        $map = [];
        foreach (glob(app_path('Domain/*/Models/*.php')) ?: [] as $file) {
            $class = 'App\\Domain\\'.basename(dirname($file, 2)).'\\Models\\'.basename($file, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $model = new $class;
            foreach ($model->getCasts() as $column => $cast) {
                if (is_string($cast) && str_starts_with($cast, 'encrypted')) {
                    $map[$model->getTable()][$column] = $cast;
                }
            }
        }

        return $map;
    }
}
