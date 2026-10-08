<?php

namespace App\Domain\Offboarding\Enums;

/** SaaS.9: a tenant export. queued → generating → ready → expired; failed (retryable); cancelled. */
enum ExportStatus: string
{
    case Queued = 'queued';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function inFlight(): bool
    {
        return $this === self::Queued || $this === self::Generating;
    }
}
