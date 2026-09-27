<?php

namespace App\Domain\Performance\Services;

use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\RatingScale;

/** Starter rating scale and competencies for every tenant (§35). */
final class PerformanceDefaults
{
    public function seed(): void
    {
        $scale = config('peopleos.performance.defaults.rating_scale');
        RatingScale::query()->firstOrCreate(['code' => $scale['code']], ['name' => $scale['name'], 'levels' => $scale['levels'], 'is_default' => true, 'status' => 'active']);

        foreach (config('peopleos.performance.defaults.competencies', []) as $row) {
            Competency::query()->firstOrCreate(['code' => $row['code']], $row + ['status' => 'active']);
        }
    }
}
