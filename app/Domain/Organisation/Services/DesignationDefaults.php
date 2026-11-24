<?php

namespace App\Domain\Organisation\Services;

use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Designation;
use Carbon\CarbonInterface;

/**
 * Master relationships R3: a designation's default level, grade and job family for one company. A shared designation
 * carries the shared values; a company may have its own (configuration overrides, effective-dated). The company's
 * value wins, otherwise the shared one. Defaults only prefill and compare: they never place anyone and never change
 * an existing position (blueprint §70).
 */
final class DesignationDefaults
{
    /** The default links a company may give its own value. */
    public const FIELDS = ['level_id' => 'Level', 'grade_id' => 'Grade', 'job_family_id' => 'Job family'];

    public const TYPE = 'designation';

    public function __construct(private readonly ConfigurationOverrides $overrides) {}

    /**
     * @return array{level_id: ?int, grade_id: ?int, job_family_id: ?int}
     */
    public function for(Designation|int|null $designation, ?int $companyId, CarbonInterface|string|null $on = null): array
    {
        $empty = ['level_id' => null, 'grade_id' => null, 'job_family_id' => null];
        if ($designation === null) {
            return $empty;
        }
        if (! $designation instanceof Designation) {
            $designation = Designation::query()->withoutGlobalScope(AccessScope::class)->find($designation);
            if ($designation === null) {
                return $empty;
            }
        }

        $values = [];
        foreach (array_keys(self::FIELDS) as $column) {
            $value = $designation->getAttribute($column);
            $values[$column] = $value === null ? null : (int) $value;
        }
        if ($companyId === null || ! $this->overrides->hasAny(self::TYPE, (int) $designation->getKey())) {
            return $values;
        }
        foreach ($this->overrides->valuesFor(self::TYPE, (int) $designation->getKey(), $companyId, $on) as $column => $value) {
            if (array_key_exists($column, $values)) {
                $values[$column] = filled($value) ? (int) $value : null;
            }
        }

        return $values;
    }

    /**
     * Fill only the blank level, grade and job family of a position from the designation's defaults for its company
     * (prefill, never force: a value already chosen is kept).
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>
     */
    public function prefill(array $position, CarbonInterface|string|null $on = null): array
    {
        if (blank($position['designation_id'] ?? null)) {
            return $position;
        }
        $company = filled($position['company_id'] ?? null) ? (int) $position['company_id'] : null;
        foreach ($this->for((int) $position['designation_id'], $company, $on) as $column => $value) {
            if ($value !== null && blank($position[$column] ?? null)) {
                $position[$column] = $value;
            }
        }

        return $position;
    }
}
