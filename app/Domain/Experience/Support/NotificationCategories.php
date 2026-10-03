<?php

namespace App\Domain\Experience\Support;

/**
 * UX: the notification center's groups (§30). A notification's group is derived from its event name;
 * nothing about delivery or who is notified changes. Groups: Needs attention, Approvals, Mentions,
 * Announcements, Updates, System.
 */
final class NotificationCategories
{
    public const GROUPS = [
        'attention' => ['Needs attention', 'heroicon-o-exclamation-triangle', 'danger'],
        'approvals' => ['Approvals', 'heroicon-o-check-badge', 'warning'],
        'mentions' => ['Mentions', 'heroicon-o-at-symbol', 'info'],
        'announcements' => ['Announcements', 'heroicon-o-megaphone', 'primary'],
        'updates' => ['Updates', 'heroicon-o-bell', 'gray'],
        'system' => ['System', 'heroicon-o-cog-6-tooth', 'gray'],
    ];

    public static function for(?string $event): string
    {
        $e = strtolower((string) $event);
        if ($e === '') {
            return 'updates';
        }
        foreach (['overdue', 'expir', 'escalat', 'sla', 'probation', 'due_soon', 'exception', 'failed', 'lost', 'dead_letter'] as $needle) {
            if (str_contains($e, $needle)) {
                return 'attention';
            }
        }
        foreach (['_requested', '.requested', 'task_assigned', 'review_requested', 'awaiting', 'submitted', 'cancel_requested', 'approval'] as $needle) {
            if (str_contains($e, $needle)) {
                return 'approvals';
            }
        }
        foreach (['comment', 'mention', 'reply', 'feedback.'] as $needle) {
            if (str_contains($e, $needle)) {
                return 'mentions';
            }
        }
        foreach (['communication.', 'campaign.', 'survey.', 'kb.', 'article.'] as $needle) {
            if (str_starts_with($e, $needle)) {
                return 'announcements';
            }
        }
        foreach (['integration.', 'webhook.', 'security.', 'platform.', 'compliance.', 'system.'] as $needle) {
            if (str_starts_with($e, $needle)) {
                return 'system';
            }
        }

        return 'updates';
    }

    /** @return array{0: string, 1: string, 2: string} label, icon, colour */
    public static function meta(string $group): array
    {
        return self::GROUPS[$group] ?? self::GROUPS['updates'];
    }
}
