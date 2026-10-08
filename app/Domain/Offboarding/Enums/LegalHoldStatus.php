<?php

namespace App\Domain\Offboarding\Enums;

/** SaaS.9: a legal hold. active and release_requested both hold; released by a second operator. */
enum LegalHoldStatus: string
{
    case Active = 'active';
    case ReleaseRequested = 'release_requested';
    case Released = 'released';

    public function holding(): bool
    {
        return $this !== self::Released;
    }
}
