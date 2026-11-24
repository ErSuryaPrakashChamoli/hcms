<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\SharedConfigurationGuard;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\ConfigurationLineage;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Organisation\Enums\ActiveStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Policy versioning: draft -> publish (effective-dated, immutable) -> retire; restore = new draft. */
final class Policies
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** The shared-record guard for a version (refused as a ConfigurationException, as screens expect from policies). */
    private function assertEditable(Policy $policy, string $verb): void
    {
        try {
            app(SharedConfigurationGuard::class)->assertEditable($policy, $verb);
        } catch (ApplicabilityRefusedException $e) {
            throw new ConfigurationException($e->getMessage(), previous: $e);
        }
    }

    /** @param  array<string, mixed>|null  $settings */
    public function draft(Policy $policy, ?array $settings = null, ?string $note = null): PolicyVersion
    {
        // A version changes the policy for every company it reaches: a person limited to some companies may not
        // change one shared beyond their access (the model guard sees only edits of the policy row itself).
        $this->assertEditable($policy, 'change');
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
        $this->assertEditable($policy, 'publish');
        $draft = $policy->draft()->first() ?? throw new ConfigurationException('Nothing to publish: create a draft first.');
        $from = Carbon::parse($effectiveFrom ?? now())->startOfDay();
        self::validateSettings($policy->type, (array) ($draft->settings ?? []));
        $activate = $this->awaitsCloneActivation($policy);

        return DB::transaction(function () use ($policy, $draft, $from, $reason, $activate) {
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

            // Configuration cloning: a copy's rules wait, inactive, for its first publish, and come into force with it.
            if ($activate) {
                $policy->assignmentRules()->where('status', ActiveStatus::Inactive->value)->get()
                    ->each(fn (PolicyAssignmentRule $rule) => $rule->withAuditReason($reason ?? 'Activated with the first publish of a cloned policy')->update(['status' => ActiveStatus::Active]));
            }

            return $draft;
        });
    }

    /**
     * HROne setup parity HP-8d / HP-8f: a policy type may name a `validator` (a class with validatePolicySettings(array))
     * that checks its answers together before they are saved by the wizard or published; it throws a
     * ConfigurationException. Types without one are not checked (as before).
     *
     * @param  array<string, mixed>  $settings
     */
    public static function validateSettings(string $type, array $settings): void
    {
        $validator = config("peopleos.policies.types.{$type}.validator");
        if (is_string($validator) && class_exists($validator)) {
            app($validator)->validatePolicySettings($settings);
        }
    }

    /**
     * A policy made by cloning (configuration cloning) that has never been published: its applicability rules were
     * created inactive, so the copy applies to nobody while it is a draft, and its first publish turns them on (rules
     * the wizard adds meanwhile wait the same way). Policy "Duplicate" became the Clone action (decision OD-10).
     */
    public function awaitsCloneActivation(Policy $policy): bool
    {
        return $policy->exists
            && $policy->versions()->whereNotNull('published_at')->doesntExist()
            && ConfigurationLineage::query()->where('adapter', 'policy')->where('role', ConfigurationLineage::ROOT)->where('target_id', $policy->id)->exists();
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
