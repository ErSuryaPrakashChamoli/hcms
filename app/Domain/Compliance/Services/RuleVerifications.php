<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Models\ComplianceEvidenceDocument;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleNotice;
use App\Domain\Compliance\Models\ComplianceRuleParameter;
use App\Domain\Compliance\Models\ComplianceRuleVerification;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        if (! in_array($rule->verification_status, [ComplianceRule::DRAFT, ComplianceRule::REVIEW], true)) {
            throw new RuntimeException("Only a draft or in-review rule can take evidence ({$rule->label()} is {$rule->verification_status}).");
        }
        $from = $rule->verification_status;
        if ($actor !== null && ! $actor->isPlatformAdmin()) {
            throw new RuntimeException('Only platform administrators maintain statutory rules.');
        }

        $evidence = $this->validateEvidence($rule, $evidence);

        return DB::transaction(function () use ($rule, $evidence, $actor, $actorLabel, $from) {
            $rule->update([
                'verification_status' => ComplianceRule::REVIEW,
                'source_url' => $evidence['source_url'],
                'source_title' => $evidence['source_title'],
                'source_published_date' => $evidence['source_published_date'],
                'authority' => $evidence['authority'] ?? $rule->authority,
            ]);
            $submission = $this->history($rule, 'submitted', $from, ComplianceRule::REVIEW, $evidence, $actor, $actorLabel, $evidence['notes'] ?? null);
            foreach ($evidence['coverage'] as $parameter => $row) {
                ComplianceRuleParameter::query()->create(['compliance_rule_id' => $rule->getKey(), 'compliance_rule_verification_id' => $submission->getKey(), 'parameter' => $parameter, 'status' => $row['status'], 'requirement_excerpt' => $row['excerpt'], 'note' => $row['note'], 'created_at' => now()]);
            }
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

        $submission = $rule->verifications()->where('action', 'submitted')->reorder()->latest('id')->first()
            ?? throw new RuntimeException('The rule has no submitted evidence.');

        if ($submission->actor_id !== null && (int) $submission->actor_id === (int) $reviewer->getKey()) {
            throw new RuntimeException('The person who submitted the evidence cannot verify it.');
        }

        // Phase 6 §6: at least one stored official evidence document.
        if (! $rule->evidenceDocuments()->exists()) {
            throw new RuntimeException('Attach the official evidence document (with its retrieval date) before verifying.');
        }
        if ($rule->evidenceDocuments()->where('uploaded_by', $reviewer->getKey())->exists() && $rule->evidenceDocuments()->where(fn ($q) => $q->whereNull('uploaded_by')->orWhere('uploaded_by', '!=', $reviewer->getKey()))->doesntExist()) {
            throw new RuntimeException('The verifier cannot rely only on evidence they uploaded themselves.');
        }

        // Phase 6 §7: every payload parameter traced to the evidence, or justified as not applicable.
        $gaps = $this->coverageGaps($rule, $submission);
        if ($gaps !== []) {
            throw new RuntimeException('Parameter coverage is incomplete: '.implode('; ', $gaps).'. Submit fuller evidence or publish a corrected version.');
        }

        // Phase 6: an open regulatory notice means the version is known to be out of date or wrongly based.
        if ($notice = $this->openNoticeFor($rule)) {
            throw new RuntimeException("An open regulatory notice affects this version: {$notice->title}. Resolve it with a new version first.");
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
     * Store an official evidence document for a DRAFT or REVIEW version (never once VERIFIED).
     */
    public function attachEvidence(ComplianceRule $rule, string $contents, string $filename, CarbonInterface|string $retrievedAt, ?string $sourceUrl = null, ?User $actor = null, ?string $actorLabel = null): ComplianceEvidenceDocument
    {
        if (! in_array($rule->verification_status, [ComplianceRule::DRAFT, ComplianceRule::REVIEW], true)) {
            throw new RuntimeException("Evidence cannot be added to a {$rule->verification_status} version; publish a corrected version instead.");
        }
        if ($actor !== null && ! $actor->isPlatformAdmin()) {
            throw new RuntimeException('Only platform administrators maintain statutory rules.');
        }
        if ($contents === '') {
            throw new RuntimeException('The evidence document is empty.');
        }
        // A person cannot claim a retrieval in the future; pack-shipped documents carry their recorded date.
        if ($actor !== null && Carbon::parse($retrievedAt)->isAfter(now()->endOfDay())) {
            throw new RuntimeException('The retrieval date cannot be in the future.');
        }

        $sha = hash('sha256', $contents);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($filename)) ?: 'evidence';
        $path = "compliance-evidence/{$rule->getKey()}/{$sha}-{$safe}";
        Storage::disk(config('peopleos.storage.compliance_disk', 'local'))->put($path, $contents);

        $document = ComplianceEvidenceDocument::query()->firstOrCreate(
            ['compliance_rule_id' => $rule->getKey(), 'sha256' => $sha],
            ['filename' => $safe, 'path' => $path, 'mime' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: null, 'size' => strlen($contents), 'source_url' => $sourceUrl, 'retrieved_at' => Carbon::parse($retrievedAt)->toDateString(), 'uploaded_by' => $actor?->getKey(), 'uploaded_label' => $actorLabel ?? $actor?->email, 'created_at' => now()],
        );

        if ($document->wasRecentlyCreated) {
            $this->audit->record(action: AuditAction::StatutoryRuleEvidenceAttached, module: 'compliance', entity: $rule, reason: "Evidence {$safe} (sha256 {$sha})", metadata: ['document_id' => $document->getKey(), 'sha256' => $sha, 'retrieved_at' => $document->retrieved_at?->toDateString(), 'source_url' => $sourceUrl], entityLabel: $rule->label(), actor: $actor);
        }

        return $document;
    }

    /** @return list<string> human-readable coverage gaps of a submission */
    public function coverageGaps(ComplianceRule $rule, ?ComplianceRuleVerification $submission = null): array
    {
        $submission ??= $rule->verifications()->where('action', 'submitted')->reorder()->latest('id')->first();
        if ($submission === null) {
            return ['no evidence submitted'];
        }

        $rows = ComplianceRuleParameter::query()->where('compliance_rule_verification_id', $submission->getKey())->get()->keyBy('parameter');
        $gaps = [];
        foreach (array_keys($rule->payload()) as $parameter) {
            $row = $rows->get($parameter);
            if ($row === null) {
                $gaps[] = "{$parameter} not traced";
            } elseif ($row->status === ComplianceRuleParameter::NOT_CONFIRMED) {
                $gaps[] = "{$parameter} not confirmed";
            } elseif ($row->status === ComplianceRuleParameter::NOT_APPLICABLE && blank($row->note)) {
                $gaps[] = "{$parameter} marked not applicable without justification";
            }
        }

        return $gaps;
    }

    public function openNoticeFor(ComplianceRule $rule): ?ComplianceRuleNotice
    {
        return ComplianceRuleNotice::query()->where('status', ComplianceRuleNotice::OPEN)
            ->where('jurisdiction', $rule->jurisdiction)->where('code', $rule->code)->get()
            ->first(fn (ComplianceRuleNotice $n) => $n->affects($rule));
    }

    /**
     * Publish a corrected version (Phase 6 §8). The corrected version is never edited; a reason is
     * required, and the new version starts as DRAFT.
     *
     * @param  array<string, mixed>  $payload
     */
    public function publishCorrection(ComplianceRule $of, array $payload, CarbonInterface|string $effectiveFrom, ?string $effectiveTo, string $reason, User $actor, ?string $name = null): ComplianceRule
    {
        if (! $actor->isPlatformAdmin()) {
            throw new RuntimeException('Only platform administrators publish statutory rule versions.');
        }
        if (blank(trim($reason))) {
            throw new RuntimeException('A correction needs a reason.');
        }

        return DB::transaction(function () use ($of, $payload, $effectiveFrom, $effectiveTo, $reason, $actor, $name) {
            $version = (int) ComplianceRule::query()->where('jurisdiction', $of->jurisdiction)->where('code', $of->code)
                ->when($of->state === null, fn ($q) => $q->whereNull('state'), fn ($q) => $q->where('state', $of->state))->max('version') + 1;

            $rule = ComplianceRule::query()->create([
                'jurisdiction' => $of->jurisdiction, 'code' => $of->code, 'state' => $of->state, 'authority' => $of->authority,
                'name' => $name ?? $of->name, 'version' => $version, 'effective_from' => Carbon::parse($effectiveFrom)->toDateString(), 'effective_to' => $effectiveTo,
                'parameters' => $payload, 'source' => $of->source, 'status' => 'active', 'verification_status' => ComplianceRule::DRAFT,
                'corrects_rule_id' => $of->getKey(), 'correction_reason' => $reason,
            ]);
            $this->history($rule, 'created', null, ComplianceRule::DRAFT, [], $actor, null, "Correction of {$of->label()}: {$reason}");
            $this->auditRule($rule, AuditAction::StatutoryRuleCreated, "Correction of {$of->label()}: {$reason}", $actor);

            return $rule;
        });
    }

    /** Close a regulatory notice by linking the version that addresses it (it still needs its own verification). */
    public function resolveNotice(ComplianceRuleNotice $notice, ComplianceRule $rule, User $actor, string $note): ComplianceRuleNotice
    {
        if (! $actor->isPlatformAdmin()) {
            throw new RuntimeException('Only platform administrators resolve regulatory notices.');
        }
        if ($notice->status !== ComplianceRuleNotice::OPEN) {
            throw new RuntimeException('The notice is already resolved.');
        }
        if ($rule->jurisdiction !== $notice->jurisdiction || $rule->code !== $notice->code || ($notice->state !== null && $rule->state !== $notice->state)) {
            throw new RuntimeException('The resolving version must be for the same rule.');
        }
        if ($notice->affects($rule) || ! $rule->isResolvable()) {
            throw new RuntimeException('Resolve the notice with a new, non-rejected version, not an affected one.');
        }
        if (! $rule->isEffectiveOn($notice->effective_date)) {
            throw new RuntimeException('The resolving version must be effective on '.$notice->effective_date->toDateString().'.');
        }
        if (blank(trim($note))) {
            throw new RuntimeException('Explain how the new version addresses the notice.');
        }

        $notice->update(['status' => ComplianceRuleNotice::RESOLVED, 'resolved_by_rule_id' => $rule->getKey(), 'resolution_note' => $note, 'resolved_by' => $actor->getKey(), 'resolved_at' => now()]);
        $this->audit->record(action: AuditAction::StatutoryRuleNoticeResolved, module: 'compliance', entity: $notice, reason: $note, metadata: ['rule' => $rule->label(), 'notice' => $notice->title], entityLabel: $notice->title, actor: $actor);

        return $notice;
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

        // Parameter coverage (Phase 6 §7): "covered" needs an excerpt; "not confirmed" and
        // "not applicable" are recorded as such (a string mapping starting with NOT CONFIRMED /
        // NOT APPLICABLE is read the same way).
        $coverage = [];
        foreach (array_keys($rule->payload()) as $parameter) {
            $value = $mapping[$parameter];
            if (is_array($value)) {
                $status = $value['status'] ?? ComplianceRuleParameter::COVERED;
                $excerpt = $value['excerpt'] ?? null;
                $note = $value['note'] ?? null;
            } else {
                $text = trim((string) $value);
                $status = match (true) {
                    stripos($text, 'NOT CONFIRMED') !== false => ComplianceRuleParameter::NOT_CONFIRMED,
                    stripos($text, 'NOT APPLICABLE') === 0 => ComplianceRuleParameter::NOT_APPLICABLE,
                    default => ComplianceRuleParameter::COVERED,
                };
                $excerpt = $status === ComplianceRuleParameter::COVERED ? $text : null;
                $note = $status === ComplianceRuleParameter::COVERED ? null : $text;
            }
            if (! in_array($status, [ComplianceRuleParameter::COVERED, ComplianceRuleParameter::NOT_CONFIRMED, ComplianceRuleParameter::NOT_APPLICABLE], true)) {
                throw new RuntimeException("Unknown coverage status [{$status}] for {$parameter}.");
            }
            if ($status === ComplianceRuleParameter::COVERED && blank($excerpt)) {
                throw new RuntimeException("Parameter {$parameter} is marked covered without the requirement it rests on.");
            }
            $coverage[$parameter] = ['status' => $status, 'excerpt' => $excerpt, 'note' => $note];
        }
        $evidence['coverage'] = $coverage;
        $evidence['retrieved_at'] = filled($evidence['retrieved_at'] ?? null) ? Carbon::parse($evidence['retrieved_at'])->toDateString() : null;

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
            'retrieved_at' => $evidence['retrieved_at'] ?? null,
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
