<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Engagement\Models\MoodReason;

/** HROne setup parity HP-9f: HROne's fourteen mood bot reasons, for every tenant (at provisioning; "Add standard reasons" later). */
final class MoodDefaults
{
    public const REASONS = [
        'Appraisal Salary', 'Appreciation', 'Colleagues', 'Employee engagement', 'Extracurricular activities', 'Facilities', 'Growth',
        'Learning opportunity', 'Management', 'Other', 'Reporting Manager', 'Work environment', 'Work timings', 'Work-life balance',
    ];

    /** @return int reasons added (existing ones, by name, are left as the organisation changed them; never past 20 active) */
    public function seed(): int
    {
        $added = 0;
        foreach (self::REASONS as $i => $name) {
            if (MoodReason::query()->where('name', $name)->exists()) {
                continue;
            }
            $active = MoodReason::query()->where('status', 'active')->count() < MoodReason::MAX_ACTIVE;
            MoodReason::query()->create(['name' => $name, 'status' => $active ? 'active' : 'inactive', 'is_system' => true, 'sort_order' => ($i + 1) * 10]);
            $added++;
        }

        return $added;
    }
}
