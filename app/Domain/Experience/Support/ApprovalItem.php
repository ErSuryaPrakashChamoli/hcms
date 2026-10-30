<?php

namespace App\Domain\Experience\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * UX: one decision waiting in the Approval Center: what, who, why, impact, effective date, risk and
 * requester, plus the decisions the viewer may take. `record` is the domain model the decision goes
 * to; the item never carries a decision of its own.
 *
 * @property-read list<array{label: string, before: ?string, after: string}> $changes
 */
final class ApprovalItem
{
    /**
     * @param  list<string>  $decisions  approve | reject | request_change | complete | send_back
     * @param  array<string, string>  $decisionLabels
     * @param  list<array{label: string, before: ?string, after: string}>  $changes  Before → After rows
     * @param  list<string>  $facts
     * @param  (Closure(): list<array{label: string, before: ?string, after: string}>)|null  $changesUsing  Before → After worked out on first read
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
        private readonly array $changes = [],
        public readonly array $facts = [],
        public readonly array $decisionLabels = [],
        public readonly ?string $status = null,
        public readonly ?CarbonInterface $decidedAt = null,
        private readonly ?Closure $changesUsing = null,
        // HROne setup parity P4: set when the item reaches the viewer through an out-of-office delegation.
        public readonly ?int $onBehalfOfUserId = null,
        public readonly ?string $onBehalfOfName = null,
        public readonly ?int $delegationId = null,
    ) {}

    /** @var list<array{label: string, before: ?string, after: string}>|null */
    private ?array $resolvedChanges = null;

    /** `$item->changes` stays the public read; leave balances behind it are looked up on first read (UX.15.20). */
    public function __get(string $name): mixed
    {
        if ($name === 'changes') {
            return $this->changes();
        }
        trigger_error('Undefined property: '.self::class.'::$'.$name, E_USER_WARNING);

        return null;
    }

    public function __isset(string $name): bool
    {
        return $name === 'changes';
    }

    /** @return list<array{label: string, before: ?string, after: string}> */
    public function changes(): array
    {
        if ($this->changes !== [] || $this->changesUsing === null) {
            return $this->changes;
        }

        return $this->resolvedChanges ??= ($this->changesUsing)();
    }

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

    /** HROne setup parity P3: the same item offering one more decision (e.g. send_back, when switched on for its type). */
    public function withDecision(string $decision, ?string $label = null): self
    {
        if ($this->can($decision)) {
            return $this;
        }

        return new self(
            $this->id, $this->type, $this->typeLabel, $this->title, $this->subject, $this->subjectEmployeeId, $this->reason, $this->impact,
            $this->effectiveOn, $this->dueAt, $this->requestedAt, $this->requestedBy, $this->risk, $this->riskReason,
            [...$this->decisions, $decision], $this->url, $this->record, $this->changes, $this->facts,
            $label === null ? $this->decisionLabels : [...$this->decisionLabels, $decision => $label], $this->status, $this->decidedAt, $this->changesUsing,
            $this->onBehalfOfUserId, $this->onBehalfOfName, $this->delegationId,
        );
    }

    /**
     * HROne setup parity P4: the same item, reaching a delegate through an out-of-office delegation. Deciding it acts in
     * the delegator's stead (ApprovalDecisions); the card says so.
     */
    public function delegatedBy(int $delegationId, int $delegatorId, string $delegatorName, ?CarbonInterface $until): self
    {
        return new self(
            $this->id, $this->type, $this->typeLabel, $this->title, $this->subject, $this->subjectEmployeeId, $this->reason, $this->impact,
            $this->effectiveOn, $this->dueAt, $this->requestedAt, $this->requestedBy, $this->risk, $this->riskReason,
            $this->decisions, $this->url, $this->record, $this->changes,
            ['On behalf of '.$delegatorName.' (out of office'.($until !== null ? ' until '.$until->format('D j M') : '').')', ...$this->facts],
            $this->decisionLabels, $this->status, $this->decidedAt, $this->changesUsing,
            $delegatorId, $delegatorName, $delegationId,
        );
    }

    public function isDelegated(): bool
    {
        return $this->delegationId !== null;
    }

    public function can(string $decision): bool
    {
        return in_array($decision, $this->decisions, true);
    }

    public function label(string $decision): string
    {
        return $this->decisionLabels[$decision] ?? match ($decision) {
            'approve' => 'Approve', 'reject' => 'Reject', 'request_change' => 'Request change', 'complete' => 'Mark done', 'send_back' => 'Send back', default => ucfirst($decision),
        };
    }

    /** Decisions that must carry a reason. */
    public function needsNote(string $decision): bool
    {
        return in_array($decision, ['reject', 'request_change', 'send_back'], true);
    }
}
