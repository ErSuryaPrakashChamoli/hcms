<?php

namespace App\Domain\Letters\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Letters\Contracts\LetterSignatureProvider;
use App\Domain\Letters\Models\Letter;
use RuntimeException;

/** HROne parity WO-13: the default e-sign binding until SV-17 chooses a provider — e-signing is not offered. */
final class NoSignatureProvider implements LetterSignatureProvider
{
    public function available(): bool
    {
        return false;
    }

    public function initiate(Letter $letter, User $actor, ?int $expiresInDays = null, bool $employeeSigns = false): string
    {
        throw new RuntimeException('E-signing is not set up (it waits for the e-sign provider decision, SV-17).');
    }
}
