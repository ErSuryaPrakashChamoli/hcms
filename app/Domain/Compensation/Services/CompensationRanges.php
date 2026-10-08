<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Events\CompensationEvent;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationRange;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 11 — grade pay ranges (§8) and the descriptive measures computed against them (§25).
 *
 * A range is drafted, submitted and approved by a second person. Approved definitions with the same
 * applicability (grade, structure, company, job family, designation, currency, frequency) never
 * overlap: a later version closes the earlier one the day before; anything else is refused. The
 * range model is configurable (peopleos.compensation.range_model): min_mid_max requires a midpoint,
 * min_max makes it optional.
 *
 * Range position and compa-ratio are arithmetic only — never a performance, pay-fairness or
 * promotion judgement.
 */
final class CompensationRanges
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): CompensationRange
    {
        $this->authorise($actor, 'compensation.configure');
        $clean = $this->validated($data);

        return CompensationRange::query()->create([...$clean, 'status' => 'draft', 'prepared_by' => $actor->id,
            'version' => (int) CompensationRange::query()->where('applicability_key', $clean['applicability_key'])->max('version') + 1]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(CompensationRange $range, array $data, User $actor): CompensationRange
    {
        $this->authorise($actor, 'compensation.configure');
        if ($range->status !== 'draft') {
            throw new CompensationRuleViolation('An approved range never changes; propose a new version.');
        }
        $range->update($this->validated($data + $range->only(CompensationRange::CONTENT) + ['notes' => $range->notes]));

        return $range;
    }

    public function submit(CompensationRange $range, User $actor): CompensationRange
    {
        $this->authorise($actor, 'compensation.configure');

        return $this->transition($range, ['draft'], function (CompensationRange $range) use ($actor) {
            $range->update(['status' => 'pending_approval', 'submitted_at' => now()]);
            $this->audit->record(AuditAction::Submitted, 'compensation', $range, [['field' => 'status', 'before' => 'draft', 'after' => 'pending_approval']], null, actor: $actor, effectiveDate: $range->effective_from);
        });
    }

    public function approve(CompensationRange $range, User $actor, ?string $note = null): CompensationRange
    {
        $this->authorise($actor, 'compensation.approve');
        if ((int) $range->prepared_by === (int) $actor->id) {
            throw new CompensationRuleViolation('The person who prepared a range cannot approve it.');
        }

        return $this->transition($range, ['pending_approval'], function (CompensationRange $range) use ($actor, $note) {
            // The grade row serialises approvals of ranges for one grade.
            Grade::query()->whereKey($range->grade_id)->lockForUpdate()->firstOrFail();
            $from = $range->effective_from->copy()->startOfDay();
            $others = CompensationRange::query()->where('applicability_key', $range->applicability_key)->where('status', 'approved')->whereKeyNot($range->id)->orderBy('effective_from')->lockForUpdate()->get();
            foreach ($others as $other) {
                if ($other->effective_from->gte($from)) {
                    throw new CompensationRuleViolation('An approved range for the same grade and applicability already starts on '.$other->effective_from->toDateString().'; a new version must start later.');
                }
                if ($range->effective_to !== null && $other->effective_to !== null && $other->effective_to->gte($from) && $other->effective_from->lte($range->effective_to)) {
                    throw new CompensationRuleViolation('The range overlaps an approved range for the same grade and applicability.');
                }
            }
            $previous = $others->last();
            if ($previous && ($previous->effective_to === null || $previous->effective_to->gte($from))) {
                $previous->update(['effective_to' => $from->copy()->subDay(), 'status' => 'superseded']);
            }
            $range->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note ?: null]);
            $this->audit->record(AuditAction::Approved, 'compensation', $range, [['field' => 'status', 'before' => 'pending_approval', 'after' => 'approved']], null, actor: $actor, effectiveDate: $from);
            CompensationEvent::dispatch('compensation.range.approved', null, $range, ['grade' => $range->grade?->code, 'version' => $range->version, 'effective_date' => $from->toDateString()], array_filter([(int) $range->prepared_by]));
        });
    }

    public function returnToDraft(CompensationRange $range, User $actor, string $note): CompensationRange
    {
        $this->authorise($actor, 'compensation.approve');
        if (trim($note) === '') {
            throw new CompensationRuleViolation('Returning a range needs a note.');
        }

        return $this->transition($range, ['pending_approval'], function (CompensationRange $range) use ($actor, $note) {
            $range->update(['status' => 'draft', 'submitted_at' => null, 'decision_note' => $note]);
            $this->audit->record(AuditAction::Rejected, 'compensation', $range, [['field' => 'status', 'before' => 'pending_approval', 'after' => 'draft']], $note, actor: $actor);
        });
    }

    public function archive(CompensationRange $range, User $actor, string $reason): CompensationRange
    {
        $this->authorise($actor, 'compensation.configure');

        return $this->transition($range, ['draft', 'pending_approval', 'superseded'], function (CompensationRange $range) use ($actor, $reason) {
            $before = $range->status;
            $range->update(['status' => 'archived']);
            $this->audit->record(AuditAction::Archive, 'compensation', $range, [['field' => 'status', 'before' => $before, 'after' => 'archived']], $reason, actor: $actor);
        });
    }

    /**
     * The most specific approved range in force on the date for a grade (designation > job family >
     * company > structure > grade only), in the given currency when one is named.
     */
    public function rangeFor(int $gradeId, CarbonInterface|string|null $date = null, ?int $companyId = null, ?int $jobFamilyId = null, ?int $designationId = null, ?int $structureId = null, ?string $currency = null): ?CompensationRange
    {
        return CompensationRange::query()->whereIn('status', ['approved', 'superseded'])->where('grade_id', $gradeId)->effectiveOn($date)
            ->when($currency, fn ($q) => $q->where('currency', strtoupper($currency)))
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
            ->where(fn ($q) => $q->whereNull('job_family_id')->orWhere('job_family_id', $jobFamilyId))
            ->where(fn ($q) => $q->whereNull('designation_id')->orWhere('designation_id', $designationId))
            ->where(fn ($q) => $q->whereNull('salary_structure_id')->orWhere('salary_structure_id', $structureId))
            ->get()->sortByDesc(fn (CompensationRange $r) => [$r->specificity(), $r->effective_from->timestamp])->first();
    }

    /**
     * Where an annual amount sits in a range (§25), explicitly defined:
     *   range position = (amount − minimum) / (maximum − minimum)   — undefined when minimum = maximum
     *   compa-ratio    = amount / midpoint                            — undefined without a midpoint
     *   band           = below (amount < minimum), within, above (amount > maximum)
     * Amounts in another currency are not compared (no conversion is invented).
     *
     * @return array{band: ?string, range_position: ?float, compa_ratio: ?float, minimum: ?float, midpoint: ?float, maximum: ?float, note: ?string}
     */
    public function position(float $annualAmount, ?CompensationRange $range, string $currency): array
    {
        $empty = ['band' => null, 'range_position' => null, 'compa_ratio' => null, 'minimum' => null, 'midpoint' => null, 'maximum' => null];
        if ($range === null) {
            return $empty + ['note' => 'No approved range applies.'];
        }
        if (strtoupper($currency) !== strtoupper($range->currency)) {
            return $empty + ['note' => "The range is in {$range->currency}; compensation in {$currency} is not compared."];
        }
        [$min, $mid, $max] = [$range->annual('minimum'), $range->annual('midpoint'), $range->annual('maximum')];
        if ($min === null || $max === null || $max < $min || $min < 0) {
            return $empty + ['note' => 'The range is invalid.'];
        }

        return [
            'band' => $annualAmount < $min ? 'below' : ($annualAmount > $max ? 'above' : 'within'),
            'range_position' => $max > $min ? round(($annualAmount - $min) / ($max - $min), 4) : null,
            'compa_ratio' => $mid !== null && $mid > 0 ? round($annualAmount / $mid, 4) : null,
            'minimum' => $min, 'midpoint' => $mid, 'maximum' => $max,
            'note' => $max == $min ? 'Minimum equals maximum: range position is undefined.' : ($mid === null ? 'No midpoint: compa-ratio is undefined.' : null),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(array $data): array
    {
        foreach (['grade_id' => Grade::class, 'salary_structure_id' => SalaryStructure::class, 'company_id' => Company::class, 'job_family_id' => JobFamily::class, 'designation_id' => Designation::class] as $column => $model) {
            if (filled($data[$column] ?? null) && ! $model::query()->whereKey($data[$column])->exists()) {
                throw new CompensationRuleViolation("The {$column} reference does not exist.");
            }
        }
        if (blank($data['grade_id'] ?? null)) {
            throw new CompensationRuleViolation('A range belongs to a grade.');
        }
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if (! in_array($currency, config('peopleos.compensation.currencies', []), true)) {
            throw new CompensationRuleViolation("[{$currency}] is not a supported ISO 4217 currency.");
        }
        $frequency = (string) ($data['frequency'] ?? 'annual');
        if (! in_array($frequency, ['annual', 'monthly'], true)) {
            throw new CompensationRuleViolation('A range is annual or monthly.');
        }
        $amount = function (string $field, bool $required) use ($data): ?float {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '') {
                if ($required) {
                    throw new CompensationRuleViolation("A range needs a {$field}.");
                }

                return null;
            }
            if (! is_numeric($value) || (float) $value < 0 || (float) $value >= 1e12) {
                throw new CompensationRuleViolation("The {$field} must be zero or more.");
            }

            return round((float) $value, 2);
        };
        $min = $amount('minimum', true);
        $max = $amount('maximum', true);
        $mid = $amount('midpoint', config('peopleos.compensation.range_model', 'min_mid_max') === 'min_mid_max');
        if ($min > $max) {
            throw new CompensationRuleViolation('The minimum must not exceed the maximum.');
        }
        if ($mid !== null && ($mid < $min || $mid > $max)) {
            throw new CompensationRuleViolation('The midpoint must lie between the minimum and the maximum.');
        }
        try {
            $from = Carbon::parse((string) ($data['effective_from'] ?? ''));
            $to = filled($data['effective_to'] ?? null) ? Carbon::parse((string) $data['effective_to']) : null;
        } catch (Throwable) {
            throw new CompensationRuleViolation('Valid effective dates are required.');
        }
        if (blank($data['effective_from'] ?? null)) {
            throw new CompensationRuleViolation('A range needs an effective date.');
        }
        if ($to !== null && $to->lt($from)) {
            throw new CompensationRuleViolation('The range ends before it starts.');
        }
        $clean = [
            'grade_id' => (int) $data['grade_id'], 'salary_structure_id' => ($data['salary_structure_id'] ?? null) ?: null, 'company_id' => ($data['company_id'] ?? null) ?: null,
            'job_family_id' => ($data['job_family_id'] ?? null) ?: null, 'designation_id' => ($data['designation_id'] ?? null) ?: null,
            'currency' => $currency, 'frequency' => $frequency, 'minimum' => $min, 'midpoint' => $mid, 'maximum' => $max,
            'effective_from' => $from->toDateString(), 'effective_to' => $to?->toDateString(), 'notes' => $data['notes'] ?? null,
        ];

        return $clean + ['applicability_key' => CompensationRange::keyFor($clean)];
    }

    /** @param  list<string>  $from */
    private function transition(CompensationRange $range, array $from, callable $step): CompensationRange
    {
        return DB::transaction(function () use ($range, $from, $step) {
            $current = CompensationRange::query()->whereKey($range->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, $from, true)) {
                throw new CompensationRuleViolation('This range is '.str_replace('_', ' ', $current->status).'; that step is not available.');
            }
            $range->setRawAttributes($current->getAttributes(), true);
            $step($range);
            $range->update(['lock_version' => (int) $current->lock_version + 1]);

            return $range;
        });
    }

    private function authorise(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new CompensationRuleViolation("This needs {$permission}.");
        }
    }
}
