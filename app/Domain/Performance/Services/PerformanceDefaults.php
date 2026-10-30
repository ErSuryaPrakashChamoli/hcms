<?php

namespace App\Domain\Performance\Services;

use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Platform\Services\SettingsRepository;

/** Starter rating scale and competencies for every tenant (§35), and the standard performance masters (HP-8a). */
final class PerformanceDefaults
{
    public function seed(): void
    {
        $scale = config('peopleos.performance.defaults.rating_scale');
        RatingScale::query()->firstOrCreate(['code' => $scale['code']], ['name' => $scale['name'], 'levels' => $scale['levels'], 'is_default' => true, 'status' => 'active']);

        foreach (config('peopleos.performance.defaults.competencies', []) as $row) {
            Competency::query()->firstOrCreate(['code' => $row['code']], $row + ['status' => 'active']);
        }

        $this->seedMasters();
    }

    /**
     * HROne setup parity HP-8a: the standard perspectives, initiative types, PIP reasons and units of measurement
     * (system rows). Idempotent: entries already there (by code) are left as the organisation changed them.
     *
     * @return int entries added
     */
    public function seedMasters(): int
    {
        $added = 0;
        foreach ((array) config('peopleos.performance.defaults.lookups', []) as $kind => $rows) {
            foreach (array_values($rows) as $i => $row) {
                $lookup = PerformanceLookup::query()->firstOrCreate(['kind' => $kind, 'code' => strtoupper($row['code'])], [
                    'name' => $row['name'], 'description' => $row['description'] ?? null, 'is_system' => true, 'status' => 'active', 'sort_order' => ($i + 1) * 10,
                ]);
                $added += $lookup->wasRecentlyCreated ? 1 : 0;
            }
        }

        $currency = strtoupper((string) app(SettingsRepository::class)->get('tenant.base_currency', 'INR')) ?: 'INR';
        foreach ((array) config('peopleos.performance.defaults.units', []) as $row) {
            $code = strtoupper(str_replace('{currency}', $currency, $row['code']));
            $unit = PerformanceUnit::query()->firstOrCreate(['code' => $code], [
                'name' => $row['name'], 'measure_type' => $row['measure_type'] ?? null, 'default_target' => $row['default_target'] ?? null,
                'is_system' => true, 'is_admin' => true, 'status' => 'active',
            ]);
            $added += $unit->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }
}
