<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollComputation;

/**
 * The tagged payroll input sources (HROne parity) and what they share: the per-day rate a source pays or recovers
 * days at, the settlement of a finalized run, and giving it back when the closed month is reopened.
 */
final class PayrollInputs
{
    /** @return list<PayrollInputSource> */
    public static function sources(): array
    {
        return array_values(iterator_to_array(app()->tagged('peopleos.payroll_inputs'), false));
    }

    /**
     * One day's pay for days-based inputs: the full-month amount of the computation's earnings on the basis
     * ('basic' = basic, 'basic_da' = basic + DA, 'gross' = lines included in gross), over the divisor
     * ('period' = the run's proration divisor, or a fixed number of days).
     */
    public static function dailyRate(PayrollComputation $c, string $basis = 'gross', string|int $divisor = 'period'): float
    {
        $monthly = 0.0;
        foreach ($c->lines as $line) {
            if ($line['type'] !== 'earning' || isset($line['basis']['source'])) {
                continue;
            }
            $full = (float) ($line['basis']['full_month'] ?? $line['amount']);
            $monthly += match ($basis) {
                'basic' => $line['classification'] === 'basic' ? $full : 0.0,
                'basic_da' => in_array($line['classification'], ['basic', 'da'], true) ? $full : 0.0,
                default => $line['include_in_gross'] ? $full : 0.0,
            };
        }
        $days = $divisor === 'period' ? (float) ($c->inputs['divisor'] ?? $c->daysInPeriod) : (float) $divisor;

        return $days > 0 ? round($monthly / $days, 2) : 0.0;
    }

    /** payroll.finalized: each source settles the records its lines in the run carried. */
    public static function onFinalized(PayrollEvent $event): void
    {
        if ($event->name !== 'payroll.finalized' || ! $event->subject instanceof PayrollRun) {
            return;
        }
        $run = $event->subject;
        $carried = self::carried($run);

        foreach (self::sources() as $source) {
            if (isset($carried[$source->key()])) {
                $source->settle($run, $carried[$source->key()]);
            }
        }
    }

    /**
     * Streamline S2 (ST-1): the run's closed month is being reopened — each source gives back what it settled. Called
     * by PayrollMonthReopen inside its transaction, before the run's entries are recalculated.
     *
     * @return array<string, int> source key => records given back
     */
    public static function unsettle(PayrollRun $run): array
    {
        $carried = self::carried($run);
        $given = [];
        foreach (self::sources() as $source) {
            if (isset($carried[$source->key()])) {
                $source->unsettle($run, $carried[$source->key()]);
                $given[$source->key()] = count(array_merge(...array_values($carried[$source->key()])));
            }
        }

        return $given;
    }

    /**
     * The suffix that keeps a ledger entry keyed by a run unique after the run's month was reopened (ST-1): the first
     * close posts "payroll-12", a close after one reopening "payroll-12-g1", and so on.
     */
    public static function generation(PayrollRun $run): string
    {
        return (int) $run->reopen_count > 0 ? '-g'.(int) $run->reopen_count : '';
    }

    /** @return array<string, array<int, list<int>>> source key => employee id => record ids the run's lines carried */
    public static function carried(PayrollRun $run): array
    {
        $carried = [];
        // Every entry of the run, whatever the reader's own access scope: settling and giving back are the whole run's.
        PayrollEntryLine::query()->whereIn('payroll_entry_id', $run->entries()->withoutGlobalScope(AccessScope::class)->select('id'))->whereNotNull('basis')
            ->with(['entry' => fn ($q) => $q->withoutGlobalScope(AccessScope::class)->select(['id', 'employee_id'])])->get(['id', 'payroll_entry_id', 'basis'])
            ->each(function (PayrollEntryLine $line) use (&$carried) {
                $source = $line->basis['source'] ?? null;
                if (is_array($source) && isset($source['key'], $source['ids'])) {
                    foreach ((array) $source['ids'] as $id) {
                        $carried[$source['key']][(int) $line->entry->employee_id][] = (int) $id;
                    }
                }
            });

        return $carried;
    }
}
