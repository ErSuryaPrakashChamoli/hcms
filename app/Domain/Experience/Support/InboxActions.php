<?php

namespace App\Domain\Experience\Support;

use App\Domain\Platform\Services\SettingsRepository;

/**
 * HROne setup parity P3 (Inbox → Inbox actions): which Approval Center request types (ApprovalCenter::kinds()) offer
 * bulk approval, a discussion and sending back, from the settings inbox.bulk_approval.types, inbox.discussion.types and
 * inbox.send_back.types (empty by default: one decision at a time, approve or reject, as before). P4 adds delegation
 * (inbox.delegation.types: what an out-of-office delegate may decide) and the manual archive (inbox.archive.types).
 */
final class InboxActions
{
    public const ACTIONS = ['bulk_approval' => 'Bulk approval', 'discussion' => 'Discussion', 'send_back' => 'Send back', 'delegation' => 'Delegate', 'archive' => 'Move to archive'];

    public static function enabled(string $action, string $kind): bool
    {
        return isset(self::ACTIONS[$action]) && in_array($kind, self::kinds($action), true);
    }

    /** @return list<string> the request types an action is switched on for */
    public static function kinds(string $action): array
    {
        return array_values(array_map('strval', array_filter((array) app(SettingsRepository::class)->get("inbox.{$action}.types", []))));
    }
}
