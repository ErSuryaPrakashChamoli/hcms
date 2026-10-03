<?php

namespace App\Domain\Experience\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * UX: one decision waiting in the Approval Center: what, who, why, impact, effective date, risk and
 * requester, plus the decisions the viewer may take. `record` is the domain model the decision goes
 * to; the item never carries a decision of its own.
 */
final class ApprovalItem
{
    /**
     * @param  list<string>  $decisions  approve | reject | request_change | complete
     * @param  array<string, string>  $decisionLabels
     * @param  list<array{label: string, before: ?string, after: string}>  $changes  Before → After rows
     * @param  list<string>  $facts
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $typeLabel,
        public readonly string $title,
        public readonly ?string $subject,
        public readonly ?int $subjectEmployeeId,
        public readonly ?string $reason,
        public readonly ?string $impact,
        public readonly ?CarbonInterface $effectiveOn,
        public readonly ?CarbonInterface $dueAt,
        public readonly ?CarbonInterface $requestedAt,
        public readonly ?string $requestedBy,
        public readonly string $risk,
        public readonly ?string $riskReason,
        public readonly array $decisions,
        public readonly ?string $url,
        public readonly Model $record,
        public readonly array $changes = [],
        public readonly array $facts = [],
        public readonly array $decisionLabels = [],
        public readonly ?string $status = null,
        public readonly ?CarbonInterface $decidedAt = null,
    ) {}

    /**
     * urgent | today | upcoming (completed items are grouped by the caller).
     * Urgent: flagged, overdue, or taking effect within a day. Today: due within two days, taking effect
     * this week, or waiting two days already. Upcoming: everything else.
     */
    public function group(?CarbonInterface $now = null): string
    {
        $now ??= now();
        if ($this->risk === 'high' || ($this->dueAt !== null && $this->dueAt->lt($now)) || ($this->effectiveOn !== null && $this->effectiveOn->lte($now->copy()->addDay()->endOfDay()))) {
            return 'urgent';
        }
        if (($this->dueAt !== null && $this->dueAt->lte($now->copy()->addDays(2)->endOfDay()))
            || ($this->effectiveOn !== null && $this->effectiveOn->lte($now->copy()->addDays(7)))
            || ($this->requestedAt !== null && $this->requestedAt->lte($now->copy()->subDays(2)))) {
            return 'today';
        }

        return 'upcoming';
    }

    public function can(string $decision): bool
    {
        return in_array($decision, $this->decisions, true);
    }

    public function label(string $decision): string
    {
        return $this->decisionLabels[$decision] ?? match ($decision) {
            'approve' => 'Approve', 'reject' => 'Reject', 'request_change' => 'Request change', 'complete' => 'Mark done', default => ucfirst($decision),
        };
    }

    /** Decisions that must carry a reason. */
    public function needsNote(string $decision): bool
    {
        return in_array($decision, ['reject', 'request_change'], true);
    }
}
