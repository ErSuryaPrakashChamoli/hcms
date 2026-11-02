<?php

namespace App\Domain\Letters\Contracts;

use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\Letter;

/**
 * HROne parity WO-13 — the e-sign seam (§23.7.1 #9, §23.7.2 #9: "Initiate Digital Signature", signature expiry, "Employee
 * will sign their own letter", signature status per signatory, audit report). Waits for SV-17 (the e-sign provider is an
 * owner decision): until a provider is bound, NoSignatureProvider says e-signing is not available and nothing offers it.
 * A provider will receive an issued letter with its signatories in order (Letter::signatoryIds()) and report each
 * signer's status; the signed file will be stored as a new version through Letters, never overwriting the issued one.
 */
interface LetterSignatureProvider
{
    /** Whether e-signing can be offered at all (a provider is configured). */
    public function available(): bool;

    /** Start e-signing an issued letter; returns the provider's reference. */
    public function initiate(Letter $letter, User $actor, ?int $expiresInDays = null, bool $employeeSigns = false): string;
}
