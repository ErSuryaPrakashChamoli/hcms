<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleVerification;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Statutory rule verification workflow (Phase 5 Parts F and G).
 *
 *   DRAFT ──submit(evidence)──▶ REVIEW ──verify──▶ VERIFIED ──(correction verified)──▶ SUPERSEDED
 *     └────────────reject────────────┴──reject──▶ REJECTED
 *
 * Evidence must cite an official source (authoritative domain), its title, publication date when
 * stated, the effective date, the requirement text and a mapping of every payload key to that requirement.
 * Only platform administrators review; the verifier is never the submitter. Every step is an
 * append-only verification record and a platform audit event.
 */
final class RuleVerifications
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function recordCreated(ComplianceRule $rule, ?User $actor = null, ?string $actorLabel = null): void
    {
        $this->history($rule, 'created', null, $rule->verification_status, [], $actor, $actorLabel, 'Version published');
        $this->auditRule($rule, AuditAction::StatutoryRuleCreated, 'Version published', $actor);
    }

    /** @param  array<string, mixed>  $evidence */
    public function submit(ComplianceRule $rule, array $evidence, ?User $actor = null, ?string $actorLabel = null): ComplianceRule
    {
        if ($rule->verification_status !== ComplianceRule::DRAFT) {
            throw new RuntimeException("Only a draft rule can be submitted for review ({$rule->label()} is {$rule->verification_status}).");
        }
        if ($actor !== null && ! $actor->isPlatformAdmin()) {
            throw new RuntimeException('Only platform administrators maintain statutory rules.');
        }

        $evidence = $this->validateEvidence($rule, $evidence);

        return DB::transaction(function () use ($rule, $evidence, $actor, $actorLabel) {
            $rule->update([
                'verification_status' => ComplianceRule::REVIEW,
                'source_url' => $evidence['source_url'],
                'source_title' => $evidence['source_title'],
                'source_published_date' => $evidence['source_published_date'],
                'authority' => $evidence['authority'] ?? $rule->authority,
            ]);
            $this->history($rule, 'submitted', ComplianceRule::DRAFT, ComplianceRule::REVIEW, $evidence, $actor, $actorLabel, $evidence['notes'] ?? null);
            $this->auditRule($rule, AuditAction::StatutoryRuleReviewed, 'Submitted for review against '.$evidence['source_url'], $actor);

            return $rule;
        });
    }

    public function verify(ComplianceRule $rule, User $reviewer, string $notes): ComplianceRule
    {
        if ($rule->verification_status !== ComplianceRule::REVIEW) {
            throw new RuntimeException('Only a rule in review can be verified.');
        }
        if (! $reviewer->isPlatformAdmin()) {
            throw new RuntimeException('Only platform administrators verify statutory rules.');
        }
        if (blank($notes)) {
            throw new RuntimeException('Verification notes are required.');
        }
        if (! $rule->checksumIntact()) {
            throw new RuntimeException('The rule payload does not match its checksum; it cannot be verified.');
        }

        $submission = $rule->verifications()->where('action', 'submitted')->latest('id')->first()
            ?? throw new RuntimeException('The rule has no submitted evidence.');

        if ($submission->actor_id !== null && (int) $submission->actor_id === (int) $reviewer->getKey()) {
            throw new RuntimeException('The person who submitted the evidence cannot verify it.');
        }

        return DB::transaction(function () use ($rule, $reviewer, $notes, $submission) {
            $rule->update([
                'verification_status' => ComplianceRule::VERIFIED,
                'verified_by' => $reviewer->getKey(),
                'verified_at' => now(),
                'verification_notes' => $notes,
            ]);
            $this->history($rule, 'verified', ComplianceRule::REVIEW, ComplianceRule::VERIFIED, $submission->only(['authority', 'source_url', 'source_title', 'source_published_date', 'effective_date', 'requirement_text', 'mapping', 'evidence_reference', 'evidence_checksum']), $reviewer, null, $notes);
            $this->auditRule($rule, AuditAction::StatutoryRuleVerified, $notes, $reviewer);

            // A verified correction supersedes earlier versions of the same rule from the same date.
            ComplianceRule::query()
                ->where('jurisdiction', $rule->jurisdiction)->where('code', $rule->code)
                ->when($rule->state === null, fn ($q) => $q->whereNull('state'), fn ($q) => $q->where('state', $rule->state))
                ->whereDate('effective_from', $rule->effective_from->toDateString())
                ->where('version', '<', $rule->version)
                ->whereNotIn('verification_status', [ComplianceRule::SUPERSEDED, ComplianceRule::REJECTED])
                ->get()
                ->each(function (ComplianceRule $old) use ($rule, $reviewer) {
                    $from = $old->verification_status;
                    $old->update(['verification_status' => ComplianceRule::SUPERSEDED, 'superseded_by_id' => $rule->getKey()]);
                    $this->history($old, 'superseded', $from, ComplianceRule::SUPERSEDED, [], $reviewer, null, 'Superseded by '.$rule->label());
                    $this->auditRule($old, AuditAction::StatutoryRuleSuperseded, 'Superseded by '.$rule->label(), $reviewer);
                });

            return $rule;
        });
    }

    public function reject(ComplianceRule $rule, User $reviewer, string $reason): ComplianceRule
    {
        if (! in_array($rule->verification_status, [ComplianceRule::DRAFT, ComplianceRule::REVIEW], true)) {
            throw new RuntimeException('Only a draft or in-review rule can be rejected.');
        }
        if (! $reviewer->isPlatformAdmin()) {
            throw new RuntimeException('Only platform administrators reject statutory rules.');
        }
        if (blank($reason)) {
            throw new RuntimeException('A reason is required to reject a rule.');
        }

        return DB::transaction(function () use ($rule, $reviewer, $reason) {
            $from = $rule->verification_status;
            $rule->update(['verification_status' => ComplianceRule::REJECTED, 'verification_notes' => $reason]);
            $this->history($rule, 'rejected', $from, ComplianceRule::REJECTED, [], $reviewer, null, $reason);
            $this->auditRule($rule, AuditAction::StatutoryRuleRejected, $reason, $reviewer);

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function validateEvidence(ComplianceRule $rule, array $evidence): array
    {
        // The publication date is recorded when the source states one; web pages often do not.
        foreach (['source_url', 'source_title', 'effective_date', 'requirement_text', 'mapping'] as $field) {
            if (blank($evidence[$field] ?? null)) {
                throw new RuntimeException("Evidence is incomplete: {$field} is required.");
            }
        }

        $host = strtolower((string) parse_url((string) $evidence['source_url'], PHP_URL_HOST));
        $scheme = strtolower((string) parse_url((string) $evidence['source_url'], PHP_URL_SCHEME));
        $allowed = config('peopleos.compliance.authoritative_domains.'.strtoupper((string) ($rule->country ?: $rule->jurisdiction)), []);
        $official = $scheme === 'https' && collect($allowed)->contains(fn (string $suffix) => $host === $suffix || str_ends_with($host, '.'.$suffix));

        if (! $official) {
            throw new RuntimeException("{$evidence['source_url']} is not an official source for {$rule->jurisdiction}. Use the authority's own site or the gazette; blogs, vendors and articles are not evidence.");
        }

        $mapping = (array) $evidence['mapping'];
        $unmapped = array_diff(array_keys($rule->payload()), array_keys($mapping));
        if ($unmapped !== []) {
            throw new RuntimeException('Every payload parameter must be mapped to the official requirement; unmapped: '.implode(', ', $unmapped).'.');
        }

        $evidence['source_published_date'] = filled($evidence['source_published_date'] ?? null) ? Carbon::parse($evidence['source_published_date'])->toDateString() : null;
        $evidence['effective_date'] = Carbon::parse($evidence['effective_date'])->toDateString();
        $evidence['mapping'] = $mapping;

        return $evidence;
    }

    /** @param  array<string, mixed>  $evidence */
    private function history(ComplianceRule $rule, string $action, ?string $from, string $to, array $evidence, ?User $actor, ?string $actorLabel, ?string $notes): ComplianceRuleVerification
    {
        return ComplianceRuleVerification::query()->create([
            'compliance_rule_id' => $rule->getKey(),
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'authority' => $evidence['authority'] ?? $rule->authority,
            'source_url' => $evidence['source_url'] ?? null,
            'source_title' => $evidence['source_title'] ?? null,
            'source_published_date' => $evidence['source_published_date'] ?? null,
            'effective_date' => $evidence['effective_date'] ?? null,
            'requirement_text' => $evidence['requirement_text'] ?? null,
            'mapping' => $evidence['mapping'] ?? null,
            'evidence_reference' => $evidence['evidence_reference'] ?? null,
            'evidence_checksum' => $evidence['evidence_checksum'] ?? null,
            'rule_checksum' => $rule->checksum,
            'actor_id' => $actor?->getKey(),
            'actor_label' => $actorLabel ?? $actor?->email,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function auditRule(ComplianceRule $rule, AuditAction $action, ?string $reason, ?User $actor): void
    {
        $this->audit->record(
            action: $action,
            module: 'compliance',
            entity: $rule,
            changes: [['field' => 'verification_status', 'before' => null, 'after' => $rule->verification_status, 'sensitive' => false]],
            reason: $reason,
            metadata: ['rule' => $rule->label(), 'code' => $rule->code, 'state' => $rule->state, 'version' => $rule->version, 'checksum' => $rule->checksum],
            entityLabel: $rule->label(),
            actor: $actor,
        );
    }
}
