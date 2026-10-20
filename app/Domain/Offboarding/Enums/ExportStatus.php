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

    /** @return list<string> the stored values of the in-flight statuses (for queries) */
    public static function inFlightValues(): array
    {
        return [self::Queued->value, self::Generating->value];
    }
}
