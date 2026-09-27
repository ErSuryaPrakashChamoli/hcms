<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Textarea;

/**
 * A "reason for change" field that is never persisted on the record. Pages and actions call
 * extract() to pull it out of the submitted data and hand it to withAuditReason().
 */
final class AuditReasonField
{
    public const NAME = 'audit_reason';

    public static function make(): Textarea
    {
        return Textarea::make(self::NAME)
            ->label('Reason for change')
            ->helperText('Recorded in the audit trail alongside the before/after values.')
            ->rows(2)
            ->maxLength(1000)
            ->columnSpanFull();
    }

    /** Remove the reason from form data and return it. */
    public static function extract(array &$data): ?string
    {
        $reason = $data[self::NAME] ?? null;
        unset($data[self::NAME]);

        return filled($reason) ? (string) $reason : null;
    }
}
