<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Models\CommunicationRecipient;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Identity\Scopes\AccessScope;

/**
 * Phase 13: `peopleos:communication:process` for one tenant. It:
 * - releases scheduled announcements whose date has come (snapshot and delivery);
 * - delivers recipients still due (new, or failed below the retry limit).
 *
 * Idempotent: release is a locked status transition and every recipient is claimed under a lock.
 */
final class CommunicationProcessor
{
    public function __construct(private readonly Communications $communications, private readonly CommunicationDelivery $delivery) {}

    /** @return array<string, int> */
    public function run(): array
    {
        $out = ['released' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => 0];
        Announcement::query()->where('status', 'scheduled')->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()))->orderBy('id')
            ->each(function (Announcement $a) use (&$out) {
                try {
                    $this->communications->release($a);
                    $out['released']++;
                } catch (EngagementRuleViolation) {
                    $out['errors']++;
                }
            });
        $due = CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($f) => $f->where('status', 'failed')->where('attempts', '<', CommunicationDelivery::MAX_ATTEMPTS)))
            ->distinct()->pluck('announcement_id');
        Announcement::query()->whereIn('id', $due)->where('status', 'published')->orderBy('id')->each(function (Announcement $a) use (&$out) {
            foreach ($this->delivery->deliver($a) as $k => $n) {
                $out[$k] += $n;
            }
        });

        return $out;
    }
}
