<?php

namespace App\Domain\Performance\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\KpiRatingFormula;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * HROne setup parity HP-8a / HP-8c: the performance masters as goals, KRAs and improvement plans use them — which units
 * of measurement a goal owner may use, units employees add for themselves, and checks that a perspective, unit, KPI
 * rating formula or PIP reason given to a record is one that exists, is active and may be used there.
 */
final class PerformanceMasters
{
    /**
     * Units for a goal owned by $owner (null = an organisation goal), as $viewer may see them, plus $keep.
     *
     * @return array<int, string>
     */
    public function unitOptions(?Employee $owner, ?User $viewer, int|string|null $keep = null): array
    {
        return PerformanceUnit::query()->where(fn ($q) => $q->where('status', 'active')->when($keep, fn ($k) => $k->orWhere('id', $keep)))
            ->orderByDesc('is_default')->orderBy('name')->get()
            ->filter(fn (PerformanceUnit $unit) => (int) $unit->id === (int) $keep || ($unit->visibleTo($viewer) && $unit->appliesTo($owner)))
            ->mapWithKeys(fn (PerformanceUnit $unit) => [$unit->id => $unit->display()])->all();
    }

    public function defaultUnit(?Employee $owner, ?User $viewer): ?PerformanceUnit
    {
        $unit = PerformanceUnit::query()->where('is_default', true)->where('status', 'active')->first();

        return $unit !== null && $unit->visibleTo($viewer) && $unit->appliesTo($owner) ? $unit : null;
    }

    public function employeesMayAddUnits(): bool
    {
        return (bool) app(SettingsRepository::class)->get('performance.units.employee_can_add', false);
    }

    /** HP-8a: a unit an employee adds for their own goals; only they (and HR) see it. */
    public function addUnitForUser(string $name, ?string $measureType, User $actor): PerformanceUnit
    {
        if (! $actor->hasPermission('performance.manage') && ! ($this->employeesMayAddUnits() && $actor->hasPermission('performance.goals'))) {
            throw new RuntimeException('Units of measurement are set up by HR.');
        }
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Give the unit a name.');
        }
        $code = Str::upper(Str::limit(Str::slug($name, '_'), 16, '')).'_U'.$actor->getKey();

        return PerformanceUnit::query()->firstOrCreate(['code' => $code], [
            'name' => Str::limit($name, 64, ''), 'measure_type' => $measureType ?: null,
            'is_admin' => $actor->hasPermission('performance.manage'), 'created_by' => $actor->getKey(), 'status' => 'active',
        ]);
    }

    /**
     * Goals and key results: the perspective must be an active perspective, the unit one the actor may use for the
     * goal's owner, the KPI rating formula an active formula. Unchanged references on an update are not re-checked.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function assertGoalReferences(array $attributes, ?User $actor, array $unchanged = []): void
    {
        $perspective = $attributes['perspective_id'] ?? null;
        if ($perspective && ! in_array('perspective_id', $unchanged, true)
            && ! PerformanceLookup::query()->ofKind('perspective')->whereKey($perspective)->where('status', 'active')->exists()) {
            throw new RuntimeException('Choose an active perspective.');
        }
        $unitId = $attributes['unit_id'] ?? null;
        if ($unitId && ! in_array('unit_id', $unchanged, true)) {
            $unit = PerformanceUnit::query()->whereKey($unitId)->where('status', 'active')->first();
            $owner = empty($attributes['employee_id']) ? null : Employee::query()->find($attributes['employee_id']);
            if ($unit === null || ! $unit->visibleTo($actor) || ! $unit->appliesTo($owner)) {
                throw new RuntimeException('This unit of measurement cannot be used for this goal.');
            }
        }
        $formula = $attributes['kpi_rating_formula_id'] ?? null;
        if ($formula && ! in_array('kpi_rating_formula_id', $unchanged, true)
            && ! KpiRatingFormula::query()->whereKey($formula)->where('status', 'active')->exists()) {
            throw new RuntimeException('Choose an active KPI rating formula.');
        }
    }

    /**
     * PIP reasons: codes of active PIP reason entries, de-duplicated (unknown codes are refused).
     *
     * @return list<string>
     */
    public function pipReasonCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($c) => strtoupper(trim((string) $c)), $codes))));
        $known = array_keys(PerformanceLookup::codeOptions('pip_reason'));
        if (($unknown = array_diff($codes, $known)) !== []) {
            throw new RuntimeException('Unknown PIP reason(s): '.implode(', ', $unknown).'.');
        }

        return $codes;
    }
}
