<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Models\StatutoryProfileCorrection;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * C12-W: the controlled way to correct an establishment statutory profile for months whose payroll is already approved
 * or finalised. A request records the original context once; a reviewer other than the requester approves or rejects
 * it; only a configuration-only correction (no payroll amount moves) can be applied, and then only while the profile
 * and payroll are exactly as approved. Finalised payroll and built packs are never changed. A correction with payroll
 * impact waits for the owner's decision on its treatment (C12-D1).
 */
final class StatutoryProfileCorrections
{
    /** The payroll lines each statute produces (StatutoryEngine). */
    public const LINES = ['EPF' => ['PF_EE', 'PF_ER', 'PF_ADMIN'], 'ESI' => ['ESI_EE', 'ESI_ER'], 'PT' => ['PT'], 'LWF' => ['LWF_EE', 'LWF_ER'], 'TDS' => ['TDS']];

    private const PROPOSABLE = ['applicable', 'effective_from', 'effective_to'];

    /** @param  array<string, mixed>  $changes  applicable, effective_from and/or effective_to */
    public function requestChange(EstablishmentStatutoryProfile $profile, array $changes, User $requester, string $reason, ?string $evidence = null): StatutoryProfileCorrection
    {
        $unknown = array_diff(array_keys($changes), self::PROPOSABLE);
        if ($changes === [] || $unknown !== []) {
            throw new RuntimeException('A correction changes only applicable, effective_from or effective_to.');
        }
        $candidate = (clone $profile)->fill($changes);

        return $this->open('update', $profile, $candidate, false, $changes, $requester, $reason, $evidence);
    }

    /** @param  array<string, mixed>  $attributes  establishment_id, statute, applicable, effective_from, effective_to */
    public function requestNew(array $attributes, User $requester, string $reason, ?string $evidence = null): StatutoryProfileCorrection
    {
        $proposed = collect($attributes)->only(['establishment_id', 'statute', ...self::PROPOSABLE])->all();

        return $this->open('create', null, new EstablishmentStatutoryProfile($proposed), false, $proposed, $requester, $reason, $evidence);
    }

    public function requestDeletion(EstablishmentStatutoryProfile $profile, User $requester, string $reason, ?string $evidence = null): StatutoryProfileCorrection
    {
        // The profile model never deletes a period that payroll past review used, correction or not: correct it instead.
        if ($profile->usedByLockedPayroll()) {
            throw new RuntimeException("This {$profile->statute} profile was applied by approved or finalised payroll and is never deleted. Request a correction that changes it instead.");
        }

        return $this->open('delete', $profile, $profile, true, null, $requester, $reason, $evidence);
    }

    public function approve(StatutoryProfileCorrection $correction, User $reviewer, string $note): StatutoryProfileCorrection
    {
        return $this->review($correction, $reviewer, $note, 'approved');
    }

    public function reject(StatutoryProfileCorrection $correction, User $reviewer, string $note): StatutoryProfileCorrection
    {
        return $this->review($correction, $reviewer, $note, 'rejected');
    }

    /** Applies an approved configuration-only correction; returns the profile as corrected (null once deleted). */
    public function apply(StatutoryProfileCorrection $correction, User $actor): ?EstablishmentStatutoryProfile
    {
        $this->authorise($actor, 'compliance.registrations.manage');

        return DB::transaction(function () use ($correction, $actor) {
            $correction = StatutoryProfileCorrection::query()->whereKey($correction->getKey())->lockForUpdate()->firstOrFail();
            if ($correction->status !== 'approved') {
                throw new RuntimeException("Only an approved correction can be applied; this one is {$correction->status}.");
            }
            // One application at a time per establishment: two approved corrections of the same profile applied at once
            // would each find the profile as reviewed, and the second would overwrite what the first applied.
            Establishment::query()->withoutGlobalScope(AccessScope::class)->whereKey($correction->establishment_id)->lockForUpdate()->first();
            if ($correction->classification !== StatutoryProfileCorrection::CONFIGURATION_ONLY) {
                throw new RuntimeException('This correction moves payroll amounts. It cannot be applied until the owner decides how payroll and returns are settled (decision C12-D1); the original payroll stays as it is.');
            }

            $profile = $correction->profile_id === null ? null : EstablishmentStatutoryProfile::query()->withoutGlobalScope(AccessScope::class)->find($correction->profile_id);
            if ($correction->action !== 'create' && ($profile === null || ! $this->unchanged($profile, $correction))) {
                throw new RuntimeException('The profile changed after the correction was approved. Raise the correction again.');
            }
            $candidate = match ($correction->action) {
                'update' => (clone $profile)->fill($correction->proposed),
                'create' => new EstablishmentStatutoryProfile($correction->proposed),
                'delete' => $profile,
            };
            $this->assertUnchanged($correction, $candidate->payrollHistoryChanges($correction->action === 'delete'));

            $audit = "Correction #{$correction->getKey()}: {$correction->reason}";
            $candidate->applyingCorrection = $correction;
            match ($correction->action) {
                'delete' => $candidate->withAuditReason($audit)->delete(),
                default => $candidate->withAuditReason($audit)->save(),
            };
            $candidate->applyingCorrection = null;

            $correction->withAuditReason($audit)->update(['status' => 'applied', 'applied_by' => $actor->getKey(), 'applied_at' => now()]);

            return $correction->action === 'delete' ? null : $candidate->fresh();
        });
    }

    /**
     * Disclosures for a report item: the applied corrections that changed what applied for a statute at an establishment
     * in any of these period ends.
     *
     * @param  list<string>  $periodEnds
     * @return list<string>
     */
    public function disclosures(int $establishmentId, string $statute, array $periodEnds): array
    {
        return StatutoryProfileCorrection::query()->where('establishment_id', $establishmentId)->where('status', 'applied')->orderBy('id')->get()
            ->flatMap(fn (StatutoryProfileCorrection $c) => collect($c->affected)
                ->filter(fn (array $a) => $a['statute'] === $statute && in_array($a['period_end'], $periodEnds, true))
                ->map(fn (array $a) => sprintf('Statutory profile corrected after payroll was finalised (correction #%d, approved %s): %s changed from %s to %s for %s. Packs built before the correction, and the correction record, keep the original.',
                    $c->getKey(), $c->reviewed_at?->format('d M Y'), $statute, $a['before'] ? 'applying' : 'not applying', $a['after'] ? 'applying' : 'not applying', Carbon::parse($a['period_end'])->format('M Y'))))
            ->values()->all();
    }

    /** @param  array<string, mixed>|null  $proposed */
    private function open(string $action, ?EstablishmentStatutoryProfile $profile, EstablishmentStatutoryProfile $candidate, bool $deleting, ?array $proposed, User $requester, string $reason, ?string $evidence): StatutoryProfileCorrection
    {
        $this->authorise($requester, 'compliance.registrations.manage');
        if (blank($reason)) {
            throw new RuntimeException('A correction needs a reason.');
        }

        $changes = $candidate->payrollHistoryChanges($deleting);
        if ($changes === []) {
            throw new RuntimeException('No month whose payroll is approved or finalised is affected. Make the change directly; no correction is needed.');
        }
        $affected = array_map(fn (array $c) => $c + $this->payroll($c), $changes);
        $impact = collect($affected)->contains(fn (array $a) => $a['impact']);

        return StatutoryProfileCorrection::query()->create([
            'company_id' => $candidate->establishment?->company_id ?? $profile?->company_id,
            'establishment_id' => $candidate->establishment_id,
            'profile_id' => $profile?->getKey(),
            'action' => $action,
            'statute' => $candidate->statute,
            'original' => $profile ? $this->snapshot($profile) : null,
            'proposed' => $proposed,
            'affected' => $affected,
            'classification' => $impact ? StatutoryProfileCorrection::PAYROLL_IMPACT : StatutoryProfileCorrection::CONFIGURATION_ONLY,
            'required_actions' => collect($affected)->filter(fn (array $a) => $a['impact'])->map(fn (array $a) => $this->requiredAction($a))->values()->all(),
            'reason' => $reason,
            'evidence' => $evidence,
            'status' => 'requested',
            'requested_by' => $requester->getKey(),
            'requested_at' => now(),
        ]);
    }

    private function review(StatutoryProfileCorrection $correction, User $reviewer, string $note, string $outcome): StatutoryProfileCorrection
    {
        $this->authorise($reviewer, 'compliance.profile_corrections.approve');
        if (blank($note)) {
            throw new RuntimeException('A review needs a note.');
        }

        return DB::transaction(function () use ($correction, $reviewer, $note, $outcome) {
            $correction = StatutoryProfileCorrection::query()->whereKey($correction->getKey())->lockForUpdate()->firstOrFail();
            if ($correction->status !== 'requested') {
                throw new RuntimeException("Only a requested correction can be reviewed; this one is {$correction->status}.");
            }
            if ((int) $correction->requested_by === (int) $reviewer->getKey()) {
                throw new RuntimeException('The person who requested the correction cannot review it.');
            }
            if ($outcome === 'approved') {
                $profile = $correction->profile_id === null ? null : EstablishmentStatutoryProfile::query()->withoutGlobalScope(AccessScope::class)->find($correction->profile_id);
                $candidate = match ($correction->action) {
                    'update' => $profile ? (clone $profile)->fill($correction->proposed) : null,
                    'create' => new EstablishmentStatutoryProfile($correction->proposed),
                    'delete' => $profile,
                };
                if ($candidate === null || ($profile !== null && ! $this->unchanged($profile, $correction))) {
                    throw new RuntimeException('The profile changed after the correction was requested. Raise the correction again.');
                }
                $this->assertUnchanged($correction, $candidate->payrollHistoryChanges($correction->action === 'delete'));
            }

            $correction->withAuditReason($note)->update(['status' => $outcome, 'reviewed_by' => $reviewer->getKey(), 'reviewed_at' => now(), 'review_note' => $note]);

            return $correction;
        });
    }

    /** @param  list<array<string, mixed>>  $changes */
    private function assertUnchanged(StatutoryProfileCorrection $correction, array $changes): void
    {
        $key = fn (array $c) => $c['period_end'].'|'.$c['statute'].'|'.(int) $c['before'].'|'.(int) $c['after'];
        $now = array_map($key, $changes);
        $then = array_map($key, $correction->approvedChanges());
        sort($now);
        sort($then);
        if ($now !== $then) {
            throw new RuntimeException('Payroll past review changed after the correction was requested, so its effect is no longer what was reviewed. Raise the correction again.');
        }
    }

    /**
     * The finalised payroll behind one affected month and statute: the runs, the amount on the statute's lines, and
     * whether correcting it would move money. A statute that applied and carried no amount (everyone exempt) moves
     * nothing; a statute that did not apply cannot be judged without recalculating, so it counts as impact.
     *
     * @param  array{establishment_id: int, period_end: string, statute: string, before: bool, after: bool}  $change
     * @return array{run_ids: list<int>, amount: float, impact: bool}
     */
    private function payroll(array $change): array
    {
        $runs = PayrollRun::query()->whereNotIn('status', PayrollRun::EDITABLE)
            ->whereHas('period', fn ($q) => $q->whereDate('end_date', $change['period_end']))
            ->whereHas('entries', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->where('establishment_id', $change['establishment_id']))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $amount = (float) PayrollEntryLine::query()->whereIn('code', self::LINES[$change['statute']])
            ->whereHas('entry', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->where('establishment_id', $change['establishment_id'])->whereIn('payroll_run_id', $runs))
            ->sum('amount');

        return ['run_ids' => $runs, 'amount' => round($amount, 2), 'impact' => ! $change['before'] || $amount != 0.0];
    }

    /** @param  array<string, mixed>  $affected */
    private function requiredAction(array $affected): string
    {
        $month = Carbon::parse($affected['period_end'])->format('M Y');

        return $affected['before']
            ? "{$affected['statute']} {$month}: ".number_format($affected['amount'], 2)." was deducted or contributed under a statute now said not to apply. Settle refunds and the {$affected['statute']} return (decision C12-D1)."
            : "{$affected['statute']} {$month}: payroll did not apply {$affected['statute']}. Work out the liability and settle contributions and the {$affected['statute']} return (decision C12-D1).";
    }

    /**
     * Whether the profile is still exactly as recorded when the correction was requested. Key order is ignored, since
     * MySQL's json column re-sorts keys on storage (as in D7); values and types must match exactly.
     */
    private function unchanged(EstablishmentStatutoryProfile $profile, StatutoryProfileCorrection $correction): bool
    {
        $now = $this->snapshot($profile);
        $then = (array) $correction->original;
        ksort($now);
        ksort($then);

        return $now === $then;
    }

    /** @return array<string, mixed> the fields of a profile that decide what applies, as stored */
    private function snapshot(EstablishmentStatutoryProfile $profile): array
    {
        return ['id' => (int) $profile->getKey(), 'establishment_id' => (int) $profile->establishment_id, 'statute' => $profile->statute, 'applicable' => (bool) $profile->applicable,
            'effective_from' => $profile->effective_from?->toDateString(), 'effective_to' => $profile->effective_to?->toDateString()];
    }

    private function authorise(User $user, string $permission): void
    {
        if (! $user->hasPermission($permission)) {
            throw new RuntimeException("This needs the {$permission} permission.");
        }
    }
}
