<?php

namespace App\Domain\Organisation\Services;

use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use Illuminate\Support\Carbon;

/**
 * Remediation I.6 (2026-11-25): a designation's level and grade must agree. A grade may belong to a level
 * (grades.level_id); when a designation's level and grade are both set and the grade belongs to a level, it must be
 * that level. A grade with no level fits any level, and either value may be blank: nothing becomes mandatory.
 *
 * The pair is checked as it applies, for the shared values and for each company with its own values (R3, configuration
 * overrides), on every date from the change on where a company's own values start or end. Every way the pair can change
 * comes through here:
 * - the designation's shared level or grade (the Designation model, so its form, upload, clone and API);
 * - a company's own level or grade, set or ended (ConfigurationOverrides, through DesignationOverrideFields), one at a
 *   time or together (ConfigurationOverrides::together(), used by "Company values…" and the designation upload);
 * - a grade moved to another level (the Grade model).
 *
 * Only pairs a change makes wrong are refused: a pair that was already wrong before the change (data from before this
 * rule) does not block an unrelated change. Historical positions are never touched (blueprint §70).
 */
final class LevelGradePairs
{
    public const TYPE = 'designation';

    public const FIELDS = ['level_id', 'grade_id'];

    /** @var array<int, ?int> grade id => its level, for this request */
    private array $levels = [];

    public function __construct(private readonly ConfigurationOverrides $overrides) {}

    /**
     * What is wrong with a pair, or null when it agrees.
     *
     * @param  array<int, ?int>  $levelOf  grade id => level id to use instead of the stored one (a grade being saved)
     */
    public function problem(?int $level, ?int $grade, array $levelOf = []): ?string
    {
        if ($level === null || $grade === null) {
            return null;
        }
        $gradeLevel = array_key_exists($grade, $levelOf) ? $levelOf[$grade]
            : ($this->levels[$grade] ??= ($id = Grade::query()->withoutGlobalScope(AccessScope::class)->whereKey($grade)->value('level_id')) === null ? null : (int) $id);
        if ($gradeLevel === null || $gradeLevel === $level) {
            return null;
        }

        return sprintf('Grade %s belongs to level %s, not %s.', $this->name(Grade::class, $grade), $this->name(Level::class, $gradeLevel), $this->name(Level::class, $level));
    }

    /**
     * The pairs that must hold from $from: the shared pair, and each company's (or only $company's) on the first day and
     * on every later day its own level or grade starts or ends. Keyed "company|date" (company 0 = the shared values).
     *
     * @param  array{level_id: ?int, grade_id: ?int}|null  $shared  the shared values to use (default: the stored ones)
     * @return array<string, array{company: int, on: string, level: ?int, grade: ?int}>
     */
    public function pairs(Designation $designation, string $from, ?array $shared = null, ?int $company = null): array
    {
        $shared ??= ['level_id' => self::int($designation->getAttribute('level_id')), 'grade_id' => self::int($designation->getAttribute('grade_id'))];
        $pairs = [];
        if ($company === null) {
            $pairs["0|{$from}"] = ['company' => 0, 'on' => $from, 'level' => $shared['level_id'], 'grade' => $shared['grade_id']];
        }
        if ($designation->getKey() === null) {
            return $pairs;
        }
        // Active values, and ended ones until their last day (an end from a later date leaves the value applying until then).
        $rows = ConfigurationOverride::query()->for(self::TYPE, (int) $designation->getKey())->whereIn('field', self::FIELDS)
            ->whereIn('status', [ConfigurationOverride::ACTIVE, ConfigurationOverride::ENDED])->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->when($company !== null, fn ($q) => $q->where('company_id', $company))->get(['company_id', 'effective_from', 'effective_to']);
        $dates = [];
        foreach ($rows as $row) {
            $dates[(int) $row->company_id][$from] = true;
            foreach ([$row->effective_from?->toDateString(), $row->effective_to?->copy()->addDay()->toDateString()] as $day) {
                if ($day !== null && $day > $from) {
                    $dates[(int) $row->company_id][$day] = true;
                }
            }
        }
        if ($company !== null) {
            $dates[$company][$from] = true;
        }
        foreach ($dates as $companyId => $days) {
            ksort($days);
            foreach (array_keys($days) as $day) {
                $values = $this->overrides->applyTo($shared, self::TYPE, (int) $designation->getKey(), $companyId, $day);
                $pairs["{$companyId}|{$day}"] = ['company' => $companyId, 'on' => $day, 'level' => self::int($values['level_id'] ?? null), 'grade' => self::int($values['grade_id'] ?? null)];
            }
        }

        return $pairs;
    }

    /**
     * Refuses (ApplicabilityRefusedException) the first pair in $after that is wrong and was not already the same, and
     * wrong, in $before.
     *
     * @param  array<string, array{company: int, on: string, level: ?int, grade: ?int}>  $after
     * @param  array<string, array{company: int, on: string, level: ?int, grade: ?int}>  $before
     * @param  array<int, ?int>  $levelOf
     */
    public function assertPairs(Designation $designation, array $after, array $before = [], array $levelOf = []): void
    {
        foreach ($after as $key => $pair) {
            $problem = $this->problem($pair['level'], $pair['grade'], $levelOf);
            if ($problem === null) {
                continue;
            }
            $old = $before[$key] ?? null;
            if ($old !== null && $old['level'] === $pair['level'] && $old['grade'] === $pair['grade'] && $this->problem($old['level'], $old['grade']) !== null) {
                continue;   // the same pair was already wrong before this change (data from before the rule)
            }
            $where = $pair['company'] === 0 ? 'its shared values' : $this->name(Company::class, $pair['company']).'\'s values';
            $when = $pair['on'] > today()->toDateString() ? ' from '.Carbon::parse($pair['on'])->format('j M Y') : '';
            throw new ApplicabilityRefusedException("{$designation->name}: {$problem} The level and grade of {$where}{$when} must agree. Change them together.");
        }
    }

    /** The designation's level or grade changed (it is being saved): its shared pair and every company's that follows it. */
    public function assertDesignationChange(Designation $designation): void
    {
        $today = today()->toDateString();
        $new = ['level_id' => self::int($designation->getAttribute('level_id')), 'grade_id' => self::int($designation->getAttribute('grade_id'))];
        $old = ['level_id' => self::int($designation->getOriginal('level_id')), 'grade_id' => self::int($designation->getOriginal('grade_id'))];
        $this->assertPairs($designation, $this->pairs($designation, $today, $new), $designation->exists ? $this->pairs($designation, $today, $old) : []);
    }

    /** A grade moves to another level: every designation that pairs it, shared or for a company, must still agree. */
    public function assertGradeChange(Grade $grade): void
    {
        if (! $grade->exists || ! $grade->isDirty('level_id')) {
            return;
        }
        $id = (int) $grade->getKey();
        $levelOf = [$id => self::int($grade->getAttribute('level_id'))];
        $designations = Designation::query()->withoutGlobalScope(AccessScope::class)->where('grade_id', $id)->pluck('id')
            ->merge(ConfigurationOverride::query()->where('configuration_type', self::TYPE)->where('field', 'grade_id')->whereIn('status', [ConfigurationOverride::ACTIVE, ConfigurationOverride::ENDED])
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()))->get(['configuration_id', 'value'])
                ->filter(fn ($row) => self::int($row->value) === $id)->pluck('configuration_id'))
            ->unique()->values();
        foreach (Designation::query()->withoutGlobalScope(AccessScope::class)->whereKey($designations)->orderBy('name')->get() as $designation) {
            $pairs = array_filter($this->pairs($designation, today()->toDateString()), fn (array $p) => $p['grade'] === $id);
            try {
                // The same pairs before the move (with the grade's stored level): one already wrong does not block it.
                $this->assertPairs($designation, $pairs, $pairs, $levelOf);
            } catch (ApplicabilityRefusedException) {
                throw new ApplicabilityRefusedException("Grade {$grade->name} cannot move to level ".$this->name(Level::class, $levelOf[$id] ?? 0)
                    .": {$designation->name} pairs it with another level. Change the designation's level and grade first.");
            }
        }
    }

    /** For a form: the message for a designation's proposed level and grade, or null. */
    public function designationFormProblem(?Designation $record, mixed $level, mixed $grade): ?string
    {
        $designation = $record !== null ? clone $record : new Designation;
        $designation->setAttribute('level_id', self::int($level));
        $designation->setAttribute('grade_id', self::int($grade));
        try {
            $this->assertDesignationChange($designation);
        } catch (ApplicabilityRefusedException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** For a form: the message for moving a grade to the proposed level, or null. */
    public function gradeFormProblem(?Grade $record, mixed $level): ?string
    {
        if ($record === null || ! $record->exists) {
            return null;
        }
        $grade = clone $record;
        $grade->setAttribute('level_id', self::int($level));
        try {
            $this->assertGradeChange($grade);
        } catch (ApplicabilityRefusedException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function name(string $class, int $id): string
    {
        return (string) ($class::query()->withoutGlobalScope(AccessScope::class)->whereKey($id)->value('name') ?? "#{$id}");
    }

    private static function int(mixed $value): ?int
    {
        return blank($value) ? null : (int) $value;
    }
}
