<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Enums\ActiveStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        self::validateSettings($policy->type, (array) ($draft->settings ?? []));

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
     * HROne setup parity (Clone on a policy card): a new policy of the same type with a copy of the source's draft (else
     * its latest version) as its draft. It has no assignment rules, so it applies to nobody until someone chooses.
     */
    public function duplicate(Policy $source, string $name, ?string $code, string $reason, User $actor): Policy
    {
        if (! $actor->can('policy.create')) {
            throw new ConfigurationException('You cannot create policies.');
        }
        if (trim($reason) === '') {
            throw new ConfigurationException('Give the reason for the change.');
        }
        $name = trim($name);
        $code = trim((string) $code) ?: Str::upper(Str::slug(Str::limit($name, 30, ''), '_'));
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $code)) {
            throw new ConfigurationException('Name the copy and give it a code of letters, numbers, dashes and underscores.');
        }
        if (Policy::query()->where('code', $code)->exists()) {
            throw new ConfigurationException("Another policy already uses the code {$code}.");
        }

        return DB::transaction(function () use ($source, $name, $code, $reason) {
            $settings = ($source->draft()->first() ?? $source->versions()->first())?->settings ?? [];
            $copy = new Policy(['type' => $source->type, 'name' => $name, 'code' => $code, 'description' => $source->description, 'status' => ActiveStatus::Active]);
            $copy->withAuditReason($reason)->save();
            $this->draft($copy, $settings, 'Copied from '.$source->name);
            $this->audit->record(AuditAction::Create, 'configuration', $copy, [], $reason, metadata: ['duplicated_from' => $source->code]);

            return $copy->refresh();
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
