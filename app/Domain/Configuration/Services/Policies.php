<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Policy versioning: draft -> publish (effective-dated, immutable) -> retire; restore = new draft. */
final class Policies
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  array<string, mixed>|null  $settings */
    public function draft(Policy $policy, ?array $settings = null, ?string $note = null): PolicyVersion
    {
        if ($existing = $policy->draft()->first()) {
            if ($settings !== null) {
                $existing->withAuditReason($note)->update(['settings' => $settings, 'change_note' => $note]);
            }

            return $existing;
        }

        $latest = $policy->versions()->first();

        return PolicyVersion::create([
            'policy_id' => $policy->id,
            'version' => ($latest?->version ?? 0) + 1,
            'settings' => $settings ?? $latest?->settings ?? [],
            'status' => VersionStatus::Draft,
            'change_note' => $note,
        ]);
    }

    public function publish(Policy $policy, CarbonInterface|string|null $effectiveFrom = null, ?string $reason = null): PolicyVersion
    {
        $draft = $policy->draft()->first() ?? throw new ConfigurationException('Nothing to publish: create a draft first.');
        $from = Carbon::parse($effectiveFrom ?? now())->startOfDay();

        return DB::transaction(function () use ($policy, $draft, $from, $reason) {
            // The previous published version ends the day before the new one starts (§69).
            $policy->versions()
                ->where('status', VersionStatus::Published)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
                ->get()
                ->each(function (PolicyVersion $previous) use ($from, $reason) {
                    if ($previous->effective_from !== null && $previous->effective_from->gt($from)) {
                        throw new ConfigurationException('A published version already starts after '.$from->toDateString().'.');
                    }

                    if ($previous->effective_from !== null && $previous->effective_from->isSameDay($from)) {
                        // Same-day correction: the earlier version never takes effect.
                        $previous->withAuditReason($reason)->update(['status' => VersionStatus::Retired, 'effective_to' => $from]);

                        return;
                    }

                    $previous->withAuditReason($reason)->update(['effective_to' => $from->copy()->subDay()]);
                });

            $draft->withAuditReason($reason)->update([
                'status' => VersionStatus::Published,
                'effective_from' => $from,
                'effective_to' => null,
                'published_by' => auth()->id(),
                'published_at' => now(),
            ]);

            $this->audit->record(AuditAction::PolicyPublished, 'configuration', $draft, reason: $reason, effectiveDate: $from, metadata: ['policy' => $policy->code, 'version' => $draft->version]);

            return $draft;
        });
    }

    /** Rollback (§75): copy an earlier version into a fresh draft; history stays intact. */
    public function restore(PolicyVersion $version, ?string $reason = null): PolicyVersion
    {
        // Read from storage, never from possibly-dirty in-memory attributes.
        $settings = $version->fresh()?->settings ?? $version->settings;
        $draft = $this->draft($version->policy, $settings, "Restored from v{$version->version}".($reason ? ": {$reason}" : ''));

        $this->audit->record(AuditAction::Restore, 'configuration', $draft, reason: $reason, metadata: ['restored_from_version' => $version->version]);

        return $draft;
    }
}
