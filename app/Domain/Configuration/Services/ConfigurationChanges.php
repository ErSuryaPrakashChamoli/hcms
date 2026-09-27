<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Enums\ChangeStatus;
use App\Domain\Configuration\Enums\RiskLevel;
use App\Domain\Configuration\Events\ConfigurationChangeProposed;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Configuration Change Centre (§71–§75). Every governed edit becomes a ConfigurationChange.
 * Depending on risk and the tenant's approval setting it is applied at once, scheduled, or
 * parked for approval. Publishing writes through the subject model so the audit trail, effective
 * dates and reasons all behave exactly as a direct edit would.
 */
final class ConfigurationChanges
{
    public function __construct(
        private readonly FeatureFlags $features,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
        private readonly ImpactPreview $impact,
    ) {}

    public function riskFor(Model|string $subject): RiskLevel
    {
        $class = is_string($subject) ? $subject : $subject::class;

        foreach (config('peopleos.configuration.risk', []) as $level => $classes) {
            if (in_array($class, $classes, true)) {
                return RiskLevel::from($level);
            }
        }

        return RiskLevel::Low;
    }

    public function isGoverned(Model|string $subject): bool
    {
        $class = is_string($subject) ? $subject : $subject::class;

        foreach (config('peopleos.configuration.risk', []) as $classes) {
            if (in_array($class, $classes, true)) {
                return true;
            }
        }

        return false;
    }

    public function requiresApproval(Model|string $subject): bool
    {
        if ($this->features->disabled('configuration.approval')) {
            return false;
        }

        $minimum = RiskLevel::tryFrom((string) $this->settings->get('configuration.approval.minimum_risk', 'medium')) ?? RiskLevel::Medium;

        return $this->riskFor($subject)->atLeast($minimum);
    }

    /**
     * Route an edit. Returns the ConfigurationChange; check ->status to learn what happened.
     *
     * @param  array<string, mixed>  $payload  attributes to apply
     */
    public function propose(Model $subject, array $payload, ?string $reason = null, CarbonInterface|string|null $effectiveFrom = null): ConfigurationChange
    {
        $from = $effectiveFrom ? Carbon::parse($effectiveFrom)->startOfDay() : null;
        $payload = $this->normalise($payload);

        $change = ConfigurationChange::create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'subject_label' => method_exists($subject, 'auditLabel') ? $subject->auditLabel() : null,
            'change_type' => 'update',
            'risk_level' => $this->riskFor($subject),
            'status' => ChangeStatus::PendingApproval,
            'before' => array_intersect_key($subject->getAttributes(), $payload),
            'payload' => $payload,
            'impact' => $this->impact->for($subject, $payload),
            'effective_from' => $from,
            'reason' => $reason,
            'requested_by' => auth()->id(),
        ]);

        if ($this->requiresApproval($subject)) {
            ConfigurationChangeProposed::dispatch($change);

            return $change;
        }

        return $this->approve($change, 'Auto-approved: below the tenant approval threshold.');
    }

    public function approve(ConfigurationChange $change, ?string $note = null): ConfigurationChange
    {
        $this->assertStatus($change, ChangeStatus::PendingApproval);

        $change->withAuditReason($note)->update([
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => $note,
            'status' => ChangeStatus::Scheduled,
        ]);

        $this->audit->record(AuditAction::Approved, 'configuration', $change, reason: $note, effectiveDate: $change->effective_from);

        return $this->isDue($change) ? $this->publish($change) : $change->refresh();
    }

    public function reject(ConfigurationChange $change, string $note): ConfigurationChange
    {
        $this->assertStatus($change, ChangeStatus::PendingApproval);

        $change->withAuditReason($note)->update([
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => $note,
            'status' => ChangeStatus::Rejected,
        ]);

        $this->audit->record(AuditAction::Rejected, 'configuration', $change, reason: $note);

        return $change;
    }

    public function discard(ConfigurationChange $change, ?string $note = null): ConfigurationChange
    {
        if (! $change->isOpen()) {
            throw new ConfigurationException('Only pending or scheduled changes can be discarded.');
        }

        $change->withAuditReason($note)->update(['status' => ChangeStatus::Discarded, 'review_note' => $note]);

        $this->audit->record(AuditAction::Cancelled, 'configuration', $change, reason: $note);

        return $change;
    }

    /** Apply the payload to the subject. Scheduled changes can be forced early by an authorised user. */
    public function publish(ConfigurationChange $change): ConfigurationChange
    {
        $this->assertStatus($change, ChangeStatus::Scheduled);

        return DB::transaction(function () use ($change) {
            $subject = $change->subject()->firstOrFail();
            $subject->withAuditReason($change->reason ?? "Configuration change #{$change->id}", "CHANGE-{$change->id}");
            $subject->update($change->payload);

            $change->withAuditReason($change->reason)->update([
                'status' => ChangeStatus::Published,
                'published_at' => now(),
            ]);

            $this->audit->record(AuditAction::PolicyPublished, 'configuration', $change, reason: $change->reason, effectiveDate: $change->effective_from, metadata: ['subject' => $change->subject_type, 'subject_id' => $change->subject_id]);

            return $change->refresh();
        });
    }

    /** Scheduler entry point: publish everything approved whose effective date has arrived. */
    public function publishDue(): int
    {
        $count = 0;

        ConfigurationChange::query()
            ->where('status', ChangeStatus::Scheduled)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()->toDateString()))
            ->orderBy('id')
            ->each(function (ConfigurationChange $change) use (&$count) {
                $this->publish($change);
                $count++;
            });

        return $count;
    }

    /** Rollback (§75): a new change that restores the "before" snapshot, itself governed and audited. */
    public function rollback(ConfigurationChange $change, ?string $reason = null): ConfigurationChange
    {
        $this->assertStatus($change, ChangeStatus::Published);

        $subject = $change->subject()->firstOrFail();
        $restore = $this->propose($subject, $change->before ?? [], $reason ?? "Rollback of change #{$change->id}");

        $restore->forceFill(['change_type' => 'rollback'])->saveQuietly();
        $change->withAuditReason($reason)->update(['status' => ChangeStatus::RolledBack, 'rolled_back_by_change_id' => $restore->id]);

        return $restore->refresh();
    }

    private function isDue(ConfigurationChange $change): bool
    {
        return $change->effective_from === null || $change->effective_from->lte(now()->startOfDay());
    }

    private function assertStatus(ConfigurationChange $change, ChangeStatus $expected): void
    {
        if ($change->status !== $expected) {
            throw new ConfigurationException(sprintf('Change #%d is %s, expected %s.', $change->id, $change->status->getLabel(), $expected->getLabel()));
        }
    }

    /** JSON-safe payload: enums to values, Carbon to dates. */
    private function normalise(array $payload): array
    {
        return array_map(fn ($v) => match (true) {
            $v instanceof \BackedEnum => $v->value,
            $v instanceof \DateTimeInterface => Carbon::instance($v)->toDateString(),
            default => $v,
        }, $payload);
    }
}
