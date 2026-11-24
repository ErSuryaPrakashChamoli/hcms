<?php

namespace App\Support\Masters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Master-relationships R1: a deactivated master leaves the pickers for new choices; every record and the history
 * that already use it keep showing it. Masters without a status column are always offered.
 */
final class ActiveMasters
{
    /** @var array<string, bool> */
    private static array $hasStatus = [];

    /**
     * The picker's options without the inactive records.
     *
     * @param  class-string<Model>  $model
     * @param  array<int|string, string>  $options  id => label
     * @return array<int|string, string>
     */
    public static function only(string $model, array $options): array
    {
        if ($options === [] || ! self::hasStatus($model)) {
            return $options;
        }
        $inactive = $model::query()->where('status', '!=', 'active')->pluck((new $model)->getKeyName())->all();

        return $inactive === [] ? $options : array_diff_key($options, array_flip(array_map('intval', $inactive)));
    }

    /** Whether the master has an active / inactive status. @param  class-string<Model>  $model */
    public static function hasStatus(string $model): bool
    {
        $instance = new $model;

        return self::$hasStatus[$instance->getConnectionName().'.'.$instance->getTable()] ??= Schema::connection($instance->getConnectionName())->hasColumn($instance->getTable(), 'status');
    }
}
