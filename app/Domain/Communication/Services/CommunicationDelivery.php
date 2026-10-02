<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Models\CommunicationRecipient;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Notifications\Services\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13: delivers a published announcement to its snapshot through the existing Notifier.
 *
 * - Each recipient is claimed under a row lock, so concurrent or repeated jobs never notify twice.
 * - The employee's preference is read under a shared lock in the same transaction, so a preference
 *   change either commits first (and is honoured) or waits for this delivery.
 * - Mandatory types ignore preferences.
 * - The notification carries the title and a pointer, never the body, so delivery logs hold no
 *   message content.
 *
 * Outcomes:
 * - sent: at least one channel accepted the message;
 * - failed: every channel failed (retried up to max_attempts);
 * - skipped: no active account, or every channel switched off.
 *
 * "Delivered" is never claimed: neither the in-app inbox nor the mailer reports provider delivery.
 */
final class CommunicationDelivery
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly Notifier $notifier, private readonly CommunicationPreferences $preferences) {}

    /**
     * One batch. New recipients first; failed ones are retried (up to MAX_ATTEMPTS) only when asked —
     * by the scheduled processor, minutes later, never in a tight loop.
     *
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function deliver(Announcement $announcement, ?int $limit = null, bool $retryFailed = true): array
    {
        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        if ($announcement->status !== 'published') {
            return $counts;
        }
        $limit ??= (int) config('peopleos.communication.delivery_batch', 200);
        $ids = $this->due($announcement, $retryFailed)->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $outcome = $this->deliverOne($announcement, (int) $id);
            if ($outcome !== null) {
                $counts[$outcome]++;
            }
        }

        return $counts;
    }

    public function pending(Announcement $announcement, bool $retryFailed = true): int
    {
        return $this->due($announcement, $retryFailed)->count();
    }

    /** @return 'sent'|'failed'|'skipped'|null null = another worker already handled it */
    public function deliverOne(Announcement $announcement, int $recipientId): ?string
    {
        return DB::transaction(function () use ($announcement, $recipientId) {
            $recipient = CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->whereKey($recipientId)->lockForUpdate()->first();
            if ($recipient === null || ! ($recipient->status === 'pending' || ($recipient->status === 'failed' && $recipient->attempts < self::MAX_ATTEMPTS))) {
                return null;
            }
            $employee = AccessScope::withoutScoping(fn () => Employee::query()->find($recipient->employee_id));
            $user = $recipient->user_id ? User::query()->find($recipient->user_id) : null;
            if ($employee === null || $user === null || ! $user->isActive()) {
                $recipient->update(['status' => 'skipped', 'skipped_reason' => 'no_account', 'processed_at' => now(), 'channels' => []]);

                return 'skipped';
            }
            $channels = $this->preferences->channelsFor($employee, $announcement->type, lock: true);
            if ($channels === []) {
                $recipient->update(['status' => 'skipped', 'skipped_reason' => 'preference', 'processed_at' => now(), 'channels' => []]);

                return 'skipped';
            }
            $type = config("peopleos.communication.types.{$announcement->type}", $announcement->type);
            $subject = ($announcement->priority === 'critical' ? 'Important — ' : '').$type.': '.$announcement->title;
            $body = $subject.'. Read it in My HR → Communications'.($announcement->requires_acknowledgement ? ' and acknowledge it.' : '.');
            $deliveries = $this->notifier->send([$user], $channels, $subject, $body, 'communication.published', $announcement);
            $sent = $deliveries->where('status', 'sent')->pluck('channel')->values()->all();
            $status = $sent !== [] ? 'sent' : 'failed';
            $recipient->update([
                'status' => $status, 'channels' => $sent, 'attempts' => $recipient->attempts + 1, 'processed_at' => now(), 'skipped_reason' => null,
                'error' => $status === 'failed' ? mb_substr((string) $deliveries->pluck('error')->filter()->first(), 0, 255) : null,
            ]);

            return $status;
        });
    }

    private function due(Announcement $announcement, bool $retryFailed = true)
    {
        return CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('announcement_id', $announcement->id)
            ->where(fn ($q) => $q->where('status', 'pending')->when($retryFailed, fn ($q) => $q->orWhere(fn ($f) => $f->where('status', 'failed')->where('attempts', '<', self::MAX_ATTEMPTS))));
    }
}
