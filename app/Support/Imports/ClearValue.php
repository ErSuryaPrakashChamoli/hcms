<?php

namespace App\Support\Imports;

/**
 * The explicit "remove this link" value in uploads (master-relationships programme, decision OD-5).
 *
 * A blank cell keeps what is on file; `(none)` removes an optional link. It can never be a real code
 * (codes are letters, digits, `-` and `_`) and reads plainly in a sheet. Matching ignores case and
 * surrounding spaces. Required links (a location's company, a legal entity's company, an
 * establishment's legal entity) refuse it at the call site.
 */
final class ClearValue
{
    public const NONE = '(none)';

    public static function isClear(mixed $cell): bool
    {
        return is_string($cell) && strtolower(trim($cell)) === self::NONE;
    }

    /** The plain-language rule for format guides and row errors. */
    public static function hint(): string
    {
        return 'Leave the cell blank to keep what is on file; write '.self::NONE.' to remove the link.';
    }
}
