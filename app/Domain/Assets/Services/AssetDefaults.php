<?php

namespace App\Domain\Assets\Services;

use App\Domain\Assets\Models\AssetCategory;

/** Starter categories (§38) for every tenant. */
final class AssetDefaults
{
    public function seed(): void
    {
        foreach (config('peopleos.assets.defaults.categories', []) as $row) {
            AssetCategory::query()->firstOrCreate(['code' => $row['code']], $row + ['status' => 'active']);
        }
    }
}
