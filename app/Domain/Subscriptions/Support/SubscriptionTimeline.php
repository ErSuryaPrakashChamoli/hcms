<?php

namespace App\Domain\Subscriptions\Support;

use App\Domain\Subscriptions\Enums\CommercialStatus;
use App\Domain\Subscriptions\Models\SubscriptionPeriod;
use Illuminate\Support\Carbon;

/**
 * SaaS.6: a subscription's commercial state on any business date, derived from its live (non-voided) periods.
 * Pure and deterministic: reading never changes anything.
 *
 * - A period covering the day gives its state and plan version.
 * - After a trial, term or grace with an explicit end, until the next period starts (or for good, when none
 *   follows), the state is EXPIRED from the day after that end. The dates decide it, whether or not anything has
 *   recorded it (`derived` = true while no row does). The settlement records only the trailing lapse; a lapse that
 *   a later operator change closed (a reactivation or cancellation dated after the end) stays derived, so every
 *   order of events gives the same state on every day.
 * - Before the first period starts, there is no state yet (the subscription is scheduled).
 */
final class SubscriptionTimeline
{
    /** @param  list<array{id: int, status: CommercialStatus, plan_version_id: int, from: string, to: ?string}>  $periods  sorted by start */
    private function __construct(public readonly array $periods) {}

    /** @param  iterable<SubscriptionPeriod>  $periods */
    public static function of(iterable $periods): self
    {
        $rows = [];
        foreach ($periods as $period) {
            $rows[] = $period->toTimelineRow();
        }
        usort($rows, fn (array $a, array $b) => [$a['from'], $a['id']] <=> [$b['from'], $b['id']]);

        return new self($rows);
    }

    /** @return array{status: CommercialStatus, plan_version_id: int, from: string, to: ?string, period_id: ?int, derived: bool}|null */
    public function stateOn(string $day): ?array
    {
        $previous = null;
        foreach ($this->periods as $row) {
            if ($row['from'] > $day) {
                return $this->lapsedAfter($previous, $row['from']); // between two periods, or before the first
            }
            if ($row['to'] === null || $row['to'] >= $day) {
                return ['status' => $row['status'], 'plan_version_id' => $row['plan_version_id'], 'from' => $row['from'], 'to' => $row['to'], 'period_id' => $row['id'], 'derived' => false];
            }
            $previous = $row;
        }

        return $this->lapsedAfter($previous, null);
    }

    /** @return array{from: string, plan_version_id: int, lapsed: CommercialStatus, period_id: int}|null the expiry the dates already decided, if the last period ends with no successor */
    public function lapse(): ?array
    {
        $last = $this->last();
        if ($last === null || $last['to'] === null || ! $last['status']->entitled()) {
            return null;
        }

        return ['from' => Carbon::parse($last['to'])->addDay()->toDateString(), 'plan_version_id' => $last['plan_version_id'], 'lapsed' => $last['status'], 'period_id' => $last['id']];
    }

    /** @return array{id: int, status: CommercialStatus, plan_version_id: int, from: string, to: ?string}|null */
    public function last(): ?array
    {
        return $this->periods === [] ? null : $this->periods[array_key_last($this->periods)];
    }

    /** @return array{id: int, status: CommercialStatus, plan_version_id: int, from: string, to: ?string}|null */
    public function first(): ?array
    {
        return $this->periods[0] ?? null;
    }

    /** The day the subscription is cancelled from, if it is (terminal). */
    public function cancelledFrom(): ?string
    {
        $last = $this->last();

        return $last !== null && $last['status'] === CommercialStatus::Cancelled ? $last['from'] : null;
    }

    /**
     * The periods from $day onwards, clipped to start on $day: what a change from $day would replace.
     *
     * @return list<array{status: string, plan_version_id: int, from: string, to: ?string}>
     */
    public function tailFrom(string $day): array
    {
        $tail = [];
        foreach ($this->periods as $row) {
            if ($row['to'] !== null && $row['to'] < $day) {
                continue;
            }
            $tail[] = ['status' => $row['status']->value, 'plan_version_id' => $row['plan_version_id'], 'from' => max($row['from'], $day), 'to' => $row['to']];
        }

        return $tail;
    }

    /** @return array{status: CommercialStatus, plan_version_id: int, from: string, to: ?string, period_id: null, derived: true}|null expired after an entitled period's explicit end, until $nextFrom */
    private function lapsedAfter(?array $previous, ?string $nextFrom): ?array
    {
        if ($previous === null || $previous['to'] === null || ! $previous['status']->entitled()) {
            return null;
        }

        return ['status' => CommercialStatus::Expired, 'plan_version_id' => $previous['plan_version_id'], 'from' => Carbon::parse($previous['to'])->addDay()->toDateString(),
            'to' => $nextFrom === null ? null : Carbon::parse($nextFrom)->subDay()->toDateString(), 'period_id' => null, 'derived' => true];
    }
}
