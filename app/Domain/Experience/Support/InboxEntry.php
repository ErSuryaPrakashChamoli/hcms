<?php

namespace App\Domain\Experience\Support;

use Carbon\CarbonInterface;

/**
 * HROne parity RQ-8: one row of the unified inbox — an Approval Center decision (`item`, decided through ApprovalDecisions
 * and the owning service) or a task of the person's own (My tasks, onboarding tasks: a reference opened on its own screen),
 * or a closed row of the "Missed by me" / "Not relevant" lists. It carries what the inbox groups, filters and searches on;
 * it never carries a decision of its own.
 */
final class InboxEntry
{
    /**
     * @param  'decision'|'task'|'onboarding'|'missed'|'not_relevant'|'archived'  $source
     * @param  array<string, mixed>  $meta  filter facts: leave_type_id, ticket_category_id, travel, employee_code, org units, relieved
     */
    public function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly string $facet,
        public readonly string $facetLabel,
        public readonly string $title,
        public readonly ?string $subject = null,
        public readonly ?int $subjectEmployeeId = null,
        public readonly ?CarbonInterface $requestedAt = null,
        public readonly ?CarbonInterface $dueAt = null,
        public readonly ?string $from = null,
        public readonly ?string $url = null,
        public readonly ?ApprovalItem $item = null,
        public readonly bool $system = false,
        public readonly ?string $overallStatus = null,
        public readonly array $meta = [],
        public readonly ?int $taskId = null,
        public readonly ?string $note = null,
    ) {}

    public function isDecision(): bool
    {
        return $this->item !== null && $this->source === 'decision';
    }

    public function isOverdue(?CarbonInterface $now = null): bool
    {
        return $this->dueAt !== null && $this->dueAt->lt($now ?? now());
    }

    public function isDueToday(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $this->dueAt !== null && ! $this->isOverdue($now) && $this->dueAt->isSameDay($now);
    }

    public function isDueTomorrow(?CarbonInterface $now = null): bool
    {
        return $this->dueAt !== null && $this->dueAt->isSameDay(($now ?? now())->copy()->addDay());
    }

    /**
     * The relative due label (HROne "DUE IN : N DAYS", "DUE : TODAY", "DUE : TOMORROW", "OVERDUE", "MISSED TO RESPOND").
     *
     * @return array{label: string, tone: string}|null
     */
    public function dueLabel(?CarbonInterface $now = null): ?array
    {
        $now ??= now();
        if ($this->source === 'missed') {
            return ['label' => 'Missed to respond', 'tone' => 'danger'];
        }
        if ($this->source === 'not_relevant') {
            return ['label' => 'Not relevant', 'tone' => 'neutral'];
        }
        if ($this->dueAt === null) {
            return null;
        }

        return match (true) {
            $this->isOverdue($now) => ['label' => 'Overdue', 'tone' => 'danger'],
            $this->isDueToday($now) => ['label' => 'Due today', 'tone' => 'warning'],
            $this->isDueTomorrow($now) => ['label' => 'Due tomorrow', 'tone' => 'warning'],
            default => ['label' => 'Due in '.($days = (int) $now->copy()->startOfDay()->diffInDays($this->dueAt->copy()->startOfDay())).' '.($days === 1 ? 'day' : 'days'), 'tone' => 'info'],
        };
    }
}
