<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Compliance\Services\StatutoryContexts;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Which statute applies to an establishment for a period (Phase 5 Part D). Holds applicability,
 * the registration used and non-numeric options only; rates always come from verified rule
 * versions. Periods never overlap per establishment and statute. A closed period may be reopened or,
 * if no payroll past review used it, deleted. No change may alter what payroll past review applied,
 * except one an approved correction covers (C12-W).
 */
#[Fillable(['tenant_id', 'company_id', 'establishment_id', 'statute', 'applicable', 'statutory_registration_id', 'settings', 'effective_from', 'effective_to', 'reason', 'created_by'])]
class EstablishmentStatutoryProfile extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates, ScopedByOrganisation;

    public const STATUTES = ['EPF', 'ESI', 'PT', 'LWF', 'TDS'];

    public string $accessScopeDimension = 'statutory_profile';

    /** Set only by StatutoryProfileCorrections::apply() for the change an approved correction covers (C12-W). Not stored. */
    public ?StatutoryProfileCorrection $applyingCorrection = null;

    protected $table = 'establishment_statutory_profiles';

    protected static function booted(): void
    {
        static::saving(function (EstablishmentStatutoryProfile $profile) {
            if (! in_array($profile->statute, self::STATUTES, true)) {
                throw new RuntimeException("Unknown statute [{$profile->statute}].");
            }

            $establishment = Establishment::query()->find($profile->establishment_id)
                ?? throw new RuntimeException('The establishment is not reachable in this tenant.');
            $profile->company_id = $establishment->company_id;

            $allowed = config("peopleos.compliance.profile_settings.{$profile->statute}", []);
            foreach ((array) $profile->settings as $key => $value) {
                if (! array_key_exists($key, $allowed)) {
                    throw new RuntimeException("[{$key}] is not a {$profile->statute} profile setting. Profiles never carry rates; rates come from verified rule versions.");
                }
                if (! is_bool($value)) {
                    throw new RuntimeException("[{$key}] must be a yes/no option.");
                }
            }

            if ($profile->statutory_registration_id !== null) {
                $registration = StatutoryRegistration::query()->find($profile->statutory_registration_id);
                if ($registration === null || (int) $registration->legal_entity_id !== (int) $establishment->legal_entity_id) {
                    throw new RuntimeException('The registration must belong to the same legal entity as the establishment.');
                }
            }

            $overlap = EstablishmentStatutoryProfile::query()->withoutGlobalScope(AccessScope::class)
                ->where('establishment_id', $profile->establishment_id)
                ->where('statute', $profile->statute)
                ->when($profile->exists, fn ($q) => $q->whereKeyNot($profile->getKey()))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $profile->effective_from))
                ->when($profile->effective_to, fn ($q, $to) => $q->where('effective_from', '<=', $to))
                ->exists();

            if ($overlap) {
                throw new RuntimeException("An overlapping {$profile->statute} profile already exists for this establishment; close it first.");
            }

            $profile->assertPayrollHistoryKept(deleting: false);
        });

        // Only a closed period nobody has paid against may be removed; an open one is closed instead.
        static::deleting(function (EstablishmentStatutoryProfile $profile) {
            if ($profile->getOriginal('effective_to') === null) {
                throw new RuntimeException("Close this {$profile->statute} profile before deleting it.");
            }

            if ($profile->usedByLockedPayroll()) {
                throw new RuntimeException("This {$profile->statute} profile was applied by an approved or finalised payroll run; it stays as history.");
            }

            $profile->assertPayrollHistoryKept(deleting: true);
        });
    }

    /**
     * Payroll past review keeps the statutory context it was calculated with, and compliance reports decide from the
     * same profiles (C12). So no change, whether closing, reopening, back-dating, editing or deleting (an
     * establishment's last profile included), may alter whether a statute applied on the period end of an approved,
     * finalised or paid run calculated from this establishment's profiles. Runs calculated from the company statutory
     * settings, before the establishment had profiles, are not part of this history (the ADR-0001 transition).
     * The one way past it is an approved correction (C12-W) whose changes are exactly the ones this change makes.
     */
    private function assertPayrollHistoryKept(bool $deleting): void
    {
        $changes = $this->payrollHistoryChanges($deleting);
        if ($changes === [] || $this->approvedCorrectionCovers($changes, $deleting)) {
            return;
        }

        $statutes = collect($changes)->pluck('statute')->unique()->implode(', ');
        $months = collect($changes)->pluck('period_end')->unique()->sort()->map(fn (string $on) => Carbon::parse($on)->format('M Y'))->implode(', ');

        throw new RuntimeException("This would change whether {$statutes} applied in {$months}, whose payroll is approved or finalised and was calculated from this establishment's profiles. That payroll keeps the profile it was calculated with: make the change effective after the last of those months, or request a correction.");
    }

    /**
     * What this change (unsaved changes on this instance, or its deletion) would alter in payroll past review: for each
     * period end and statute whose answer differs, the answer before and after. Read-only.
     *
     * @return list<array{establishment_id: int, period_end: string, statute: string, before: bool, after: bool}>
     */
    public function payrollHistoryChanges(bool $deleting = false): array
    {
        $contexts = app(StatutoryContexts::class);
        $changes = [];

        foreach (array_unique(array_filter([(int) $this->getOriginal('establishment_id'), (int) $this->establishment_id])) as $establishmentId) {
            $dates = self::lockedPayrollDates($establishmentId);
            $establishment = $dates === [] ? null : Establishment::query()->find($establishmentId);
            if ($establishment === null) {
                continue;
            }

            $stored = self::query()->withoutGlobalScope(AccessScope::class)->where('establishment_id', $establishmentId)->get();
            $proposed = $stored->reject(fn (self $p) => $this->exists && $p->is($this));
            if (! $deleting && (int) $this->establishment_id === $establishmentId) {
                $proposed->push($this);
            }

            $before = $contexts->applicability($establishment, $dates, $stored);
            $after = $contexts->applicability($establishment, $dates, $proposed);
            foreach ($dates as $on) {
                foreach (array_keys(array_diff_assoc($after[$on], $before[$on])) as $statute) {
                    $changes[] = ['establishment_id' => $establishmentId, 'period_end' => $on, 'statute' => $statute, 'before' => $before[$on][$statute], 'after' => $after[$on][$statute]];
                }
            }
        }

        return $changes;
    }

    /**
     * Whether an approved correction covers this exact change: the same action on the same profile with the same
     * proposed values, and the same effect on payroll past review. An equivalent change is not enough.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    private function approvedCorrectionCovers(array $changes, bool $deleting): bool
    {
        $correction = $this->applyingCorrection;
        if ($correction === null || $correction->status !== 'approved' || (int) $correction->establishment_id !== (int) $this->establishment_id
            || $correction->statute !== $this->statute || $correction->action !== ($deleting ? 'delete' : ($this->exists ? 'update' : 'create'))
            || ($this->exists && (int) $correction->profile_id !== (int) $this->getKey())) {
            return false;
        }
        $value = fn (string $field, mixed $v) => $v === null ? null : ($field === 'applicable' ? (bool) $v : Carbon::parse($v)->toDateString());
        foreach (array_intersect_key((array) $correction->proposed, array_flip(['applicable', 'effective_from', 'effective_to'])) as $field => $proposed) {
            if ($value($field, $this->getAttribute($field)) !== $value($field, $proposed)) {
                return false;
            }
        }
        $key = fn (array $c) => $c['period_end'].'|'.$c['statute'].'|'.(int) $c['before'].'|'.(int) $c['after'];
        $made = array_map($key, $changes);
        $approved = array_map($key, $correction->approvedChanges());
        sort($made);
        sort($approved);

        return collect($changes)->every(fn (array $c) => (int) $c['establishment_id'] === (int) $correction->establishment_id) && $made === $approved;
    }

    /** @return list<string> period ends of runs past review with entries calculated from this establishment's profiles */
    private static function lockedPayrollDates(int $establishmentId): array
    {
        return PayrollRun::query()->with('period')->whereNotIn('status', PayrollRun::EDITABLE)
            ->whereHas('entries', fn ($entries) => $entries->withoutGlobalScope(AccessScope::class)->where('establishment_id', $establishmentId)
                ->where('inputs->statutory_context->profile_source', 'establishment_profile'))
            ->get()->map(fn (PayrollRun $run) => $run->period->end_date->toDateString())->unique()->sort()->values()->all();
    }

    /** Whether a payroll run past review resolved this profile (period end date inside the profile window). */
    public function usedByLockedPayroll(): bool
    {
        return PayrollEntry::query()->withoutGlobalScope(AccessScope::class)
            ->where('establishment_id', $this->establishment_id)
            ->whereHas('run', fn ($run) => $run->whereNotIn('status', PayrollRun::EDITABLE)
                ->whereHas('period', fn ($period) => $period->whereBetween('end_date', [$this->effective_from->toDateString(), $this->effective_to?->toDateString() ?? '9999-12-31'])))
            ->exists();
    }

    protected function casts(): array
    {
        return [
            'applicable' => 'boolean',
            'settings' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function setting(string $key): bool
    {
        return (bool) ($this->settings[$key] ?? config("peopleos.compliance.profile_settings.{$this->statute}.{$key}.default", false));
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function auditLabel(): string
    {
        return "{$this->statute} profile";
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(StatutoryRegistration::class, 'statutory_registration_id');
    }
}
