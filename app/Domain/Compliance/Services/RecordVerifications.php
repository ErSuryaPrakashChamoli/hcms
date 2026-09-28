<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Events\ComplianceEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Phase 6.3 maker-checker verification of legal entities and establishments against their
 * authoritative documents (incorporation certificate, registration certificate). The maker submits
 * the document reference (and optionally a stored copy); a different person with the verify
 * permission confirms it. Editing an identity field later resets the record to unverified.
 */
final class RecordVerifications
{
    public function __construct(private readonly AuditRecorder $audit, private readonly AccessScopes $scopes) {}

    public function submit(LegalEntity|Establishment $record, User $maker, string $reference, ?string $document = null, ?string $filename = null, ?string $notes = null): LegalEntity|Establishment
    {
        $this->requirePermission($maker, $record, 'update');
        if (blank(trim($reference))) {
            throw new RuntimeException('Record the certificate or document reference the details were checked against.');
        }
        if ($record->verification_status === 'verified') {
            throw new RuntimeException('Already verified; change an identity field (which resets verification) to re-verify.');
        }

        $path = null;
        $sha = null;
        if ($document !== null && $document !== '') {
            $sha = hash('sha256', $document);
            $path = 'compliance-evidence/'.$record->getTable()."/{$record->tenant_id}/{$record->getKey()}/{$sha}-".(preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) $filename)) ?: 'document');
            Storage::disk('local')->put($path, $document);
        }

        $record->forceFill(['verification_status' => 'review', 'verification_reference' => trim($reference), 'verification_evidence_path' => $path, 'verification_evidence_sha256' => $sha, 'verification_submitted_by' => $maker->getKey(), 'verification_submitted_at' => now(), 'verification_notes' => $notes, 'verified_by' => null, 'verified_at' => null])
            ->withAuditReason('Submitted for verification against '.trim($reference))->withAuditAction(AuditAction::VerificationSubmitted)->save();

        return $record;
    }

    public function verify(LegalEntity|Establishment $record, User $checker, string $notes): LegalEntity|Establishment
    {
        $this->requirePermission($checker, $record, 'verify');
        if ($record->verification_status !== 'review') {
            throw new RuntimeException('Only a record submitted for verification can be verified.');
        }
        if ((int) $record->verification_submitted_by === (int) $checker->getKey()) {
            throw new RuntimeException('The person who submitted the details cannot verify them.');
        }
        if (blank(trim($notes))) {
            throw new RuntimeException('Verification notes are required.');
        }

        $action = $record instanceof LegalEntity ? AuditAction::LegalEntityVerified : AuditAction::EstablishmentVerified;
        $record->forceFill(['verification_status' => 'verified', 'verified_by' => $checker->getKey(), 'verified_at' => now(), 'verification_notes' => $notes])
            ->withAuditReason($notes)->withAuditAction($action)->save();

        if ($record instanceof Establishment) {
            ComplianceEvent::dispatch('compliance.establishment_verified', $record, ['establishment_id' => $record->getKey(), 'legal_entity_id' => $record->legal_entity_id]);
        }

        return $record;
    }

    private function requirePermission(User $user, LegalEntity|Establishment $record, string $ability): void
    {
        $permission = ($record instanceof LegalEntity ? 'legal_entity.' : 'establishment.').$ability;
        if (! $user->hasPermission($permission) || ! $this->scopes->allows($user, $record)) {
            throw new RuntimeException("You do not have the {$permission} permission for this record.");
        }
    }
}
