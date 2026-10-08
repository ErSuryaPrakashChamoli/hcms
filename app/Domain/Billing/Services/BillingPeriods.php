<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\BillingPeriodKind;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PricingBasis;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\BillingPeriod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\NegotiatedPriceVersion;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\SubscriptionBillingTerm;
use App\Domain\Billing\Support\InvoiceLineInput;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Enums\CommercialStatus;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Support\Commercial\OperatorChange;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SaaS.7 completion (B-1, B-2, B-3, approved): the billing run. For each subscription with billing terms it
 * calculates the periods that are due and drafts one invoice per period with an amount (issue stays an operator
 * step, gated by a verified tax rule). Idempotent: a period exists once per subscription, kind and start (unique
 * index), created with its draft in one transaction; a period already calculated is never recalculated.
 *
 * - A day is billable when terms are in force and the subscription is active or in grace (trials are not billed).
 * - Monthly terms: each calendar month, after it ends, on max(monthly peak, minimum) × unit, prorated by billable
 *   days ÷ days in the month when only part of it was billable (the first and last months), rounded once half up.
 * - Annual terms: each 12-month term when it starts (in advance) on the committed quantity × unit × 12; each
 *   calendar month after it ends, the peak above the commitment × the same unit (true-up, in arrears).
 * - The quantity and its evidence are frozen on the period and the invoice line; a period that cannot be billed
 *   safely (terms of another plan, two terms in one month) is recorded as an exception, unbilled.
 *
 * SaaS.7 configuration: the price comes from the term's source, the customer's agreed price or the standard
 * catalogue (CUSTOMER AGREED TERMS > PLAN PRICE VERSION > NO PRICE). An agreed discount is applied once to the unit
 * (the net unit, with the configured rounding) and frozen on the period with the list unit. A flat (fixed monthly)
 * price is billed as one unit per month, prorated like any month, and × 12 in advance for annual terms (no true-up).
 * Billable days after the first terms with no terms in force are an exception, NO_PRICE_CONFIGURED: nothing is
 * billed at zero, at a guessed price, at another market's price or after a currency conversion.
 */
final class BillingPeriods
{
    public function __construct(private readonly BillableQuantity $quantity, private readonly Invoices $invoices, private readonly BillingCatalog $catalog,
        private readonly BillingAudit $audit, private readonly TenantContext $tenants) {}

    /** @return array{created: int, drafted: int, nothing_due: int, exceptions: int} */
    public function run(Tenant $tenant, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $summary = ['created' => 0, 'drafted' => 0, 'nothing_due' => 0, 'exceptions' => 0];

        return $this->tenants->runAs($tenant, function () use ($tenant, $asOf, $summary) {
            $ids = SubscriptionBillingTerm::query()->where('status', SubscriptionBillingTerm::ACTIVE)->distinct()->orderBy('subscription_id')->pluck('subscription_id');
            foreach ($ids as $id) {
                $subscription = TenantSubscription::query()->with('periods')->findOrFail($id);
                foreach ($this->due($subscription, $asOf) as $due) {
                    $status = $this->calculate($tenant, $subscription, $due);
                    if ($status !== null) {
                        $summary['created']++;
                        $summary[match ($status) {
                            BillingPeriod::DRAFTED => 'drafted', BillingPeriod::NOTHING_DUE => 'nothing_due', default => 'exceptions'
                        }]++;
                    }
                }
            }

            return $summary;
        });
    }

    /** Drafts again a period whose draft was discarded, from its frozen quantity and amount (never from today's data). */
    public function redraft(BillingPeriod $period, string $reason, User $actor): Invoice
    {
        OperatorChange::assert($actor, $reason, 'invoices');
        $tenant = Tenant::query()->findOrFail($period->tenant_id);

        return $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $period, $reason, $actor) {
            $locked = BillingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $previous = $locked->invoice_id === null ? null : Invoice::query()->findOrFail($locked->invoice_id);
            if ($locked->status !== BillingPeriod::DRAFTED || $previous?->status !== InvoiceStatus::Discarded) {
                throw new RuntimeException('Only a period whose draft was discarded can be drafted again.');
            }
            $attempt = Invoice::query()->where('idempotency_key', 'like', "billing-period:{$locked->id}%")->count() + 1;
            $invoice = $this->draft($tenant, $locked, "billing-period:{$locked->id}:{$attempt}", "Redraft: {$reason}");
            $locked->forceFill(['invoice_id' => $invoice->id])->save();
            $this->audit->both(AuditAction::BillingPeriodRedrafted, 'billing', $tenant, $locked, $this->label($locked),
                [['field' => 'invoice', 'before' => $previous->reference, 'after' => $invoice->reference]], $reason, $actor,
                ['billing_period_id' => $locked->id, 'invoice_reference' => $invoice->reference]);

            return $invoice;
        }));
    }

    /** @return Collection<int, BillingPeriod> the periods of a tenant, newest first */
    public function periods(Tenant $tenant, int $limit = 100): Collection
    {
        return $this->tenants->runAs($tenant, fn () => BillingPeriod::query()->with('invoice')->orderByDesc('period_start')->orderByDesc('id')->limit($limit)->get());
    }

    /**
     * What is due on $asOf and not calculated yet.
     *
     * @return list<array{kind: BillingPeriodKind, start: string, end: string, days: list<string>, unpriced: list<string>, terms: list<SubscriptionBillingTerm>, states: array<string, ?array>, last_term: SubscriptionBillingTerm}>
     */
    private function due(TenantSubscription $subscription, string $asOf): array
    {
        $terms = SubscriptionBillingTerm::query()->where(['subscription_id' => $subscription->id, 'status' => SubscriptionBillingTerm::ACTIVE])->orderBy('effective_from')->get();
        if ($terms->isEmpty()) {
            return [];
        }
        $known = BillingPeriod::query()->where('subscription_id', $subscription->id)->get(['kind', 'period_start'])
            ->mapWithKeys(fn (BillingPeriod $p) => ["{$p->kind->value}|{$p->period_start->toDateString()}" => true])->all();
        $timeline = $subscription->timeline();
        $billable = fn (?array $state) => $state !== null && in_array($state['status'], [CommercialStatus::Active, CommercialStatus::Grace], true);
        $termOn = fn (string $day) => $terms->first(fn (SubscriptionBillingTerm $t) => $t->effective_from->toDateString() <= $day
            && ($t->effective_to === null || $t->effective_to->toDateString() >= $day));
        $due = [];

        // Annual terms: each 12-month term year, in advance, from its first day.
        foreach ($terms->where('interval', BillingInterval::Year) as $term) {
            $start = $term->effective_from->copy();
            while ($start->toDateString() <= $asOf && ($term->effective_to === null || $start->toDateString() <= $term->effective_to->toDateString())) {
                $day = $start->toDateString();
                $state = $timeline->stateOn($day);
                if (! isset($known[BillingPeriodKind::AnnualAdvance->value.'|'.$day]) && $billable($state)) {
                    $end = $start->copy()->addMonthsNoOverflow(12)->subDay()->toDateString();
                    $due[] = ['kind' => BillingPeriodKind::AnnualAdvance, 'start' => $day, 'end' => $end, 'days' => [$day], 'unpriced' => [], 'terms' => [$term],
                        'states' => [$day => $state], 'last_term' => $term];
                }
                $start->addMonthsNoOverflow(12);
            }
        }

        // Calendar months that have ended: monthly in arrears, or the true-up of an annual term.
        // A billable day after the first terms with no terms in force has no price: it is reported, never guessed.
        $firstTerm = $terms->min(fn (SubscriptionBillingTerm $t) => $t->effective_from->toDateString());
        $month = Carbon::parse($firstTerm)->startOfMonth();
        while ($month->copy()->endOfMonth()->toDateString() < $asOf) {
            $days = [];
            $unpriced = [];
            $states = [];
            $used = [];
            for ($d = $month->copy(); $d->month === $month->month; $d->addDay()) {
                $day = $d->toDateString();
                $term = $termOn($day);
                $state = $timeline->stateOn($day);
                if (! $billable($state) || $day < $firstTerm) {
                    continue;
                }
                $states[$day] = $state;
                if ($term === null) {
                    $unpriced[] = $day;
                } else {
                    $days[] = $day;
                    $used[$term->id] = $term;
                }
            }
            $flatAnnual = $used !== [] && collect($used)->every(fn (SubscriptionBillingTerm $t) => $t->interval === BillingInterval::Year && $t->basis === PricingBasis::Flat);
            if (($days !== [] && ! $flatAnnual) || $unpriced !== []) {
                $first = reset($used);
                $kind = $first !== false && $first->interval === BillingInterval::Year ? BillingPeriodKind::AnnualTrueUp : BillingPeriodKind::MonthlyArrears;
                $start = $month->toDateString();
                if (! isset($known["{$kind->value}|{$start}"])) {
                    $due[] = ['kind' => $kind, 'start' => $start, 'end' => $month->copy()->endOfMonth()->toDateString(), 'days' => $days, 'unpriced' => $unpriced,
                        'terms' => array_values($used), 'states' => $states,
                        'last_term' => $terms->filter(fn (SubscriptionBillingTerm $t) => $t->effective_from->toDateString() <= $month->copy()->endOfMonth()->toDateString())->last()];
                }
            }
            $month->addMonthNoOverflow()->startOfMonth();
        }
        usort($due, fn (array $a, array $b) => [$a['start'], $a['kind']->value] <=> [$b['start'], $b['kind']->value]);

        return $due;
    }

    /** @param  array{kind: BillingPeriodKind, start: string, end: string, days: list<string>, unpriced: list<string>, terms: list<SubscriptionBillingTerm>, states: array<string, ?array>, last_term: SubscriptionBillingTerm}  $due */
    private function calculate(Tenant $tenant, TenantSubscription $subscription, array $due): ?string
    {
        $kind = $due['kind'];
        $daysInPeriod = (int) Carbon::parse($due['start'])->diffInDays(Carbon::parse($due['end'])) + 1;
        if ($due['terms'] === []) {
            return $this->unpriced($tenant, $subscription, $due, $daysInPeriod);
        }
        $term = $due['terms'][0];
        $rounding = $this->rounding($due['start']);
        $point = $this->pricePoint($term, $rounding);
        $unit = $point['net'];
        [$firstDay, $lastDay] = $kind === BillingPeriodKind::AnnualAdvance ? [$due['start'], $due['end']] : [$due['days'][0], end($due['days'])];
        $firstState = $due['states'][$due['days'][0]];
        $exception = match (true) {
            count($due['terms']) > 1 => 'More than one billing term in this month: bill it by hand (a credit note corrects any error).',
            $due['unpriced'] !== [] => 'NO_PRICE_CONFIGURED: '.count($due['unpriced']).' billable days of this month ('.$due['unpriced'][0].' to '.end($due['unpriced'])
                .') have no billing terms (no standard or agreed price pinned): pin terms and bill them by hand.',
            $firstState['plan_version_id'] !== $term->plan_version_id => 'The subscription is on another plan than its billing terms: re-pin the terms from the next period.',
            default => null,
        };
        $flat = $term->basis === PricingBasis::Flat;

        $measured = null;
        if ($kind === BillingPeriodKind::AnnualAdvance) {
            $daysBilled = $daysInPeriod;
            $billed = $flat ? 1 : (int) $term->committed_quantity;
            $amount = $exception === null ? Invoices::lineAmount($unit->times(12), $billed) : Money::zero($unit->currency);
            $evidence = $flat ? ['method' => 'flat', 'months' => 12, 'computed_at' => now()->toIso8601String()]
                : ['method' => 'committed_quantity', 'committed_quantity' => $billed, 'months' => 12, 'computed_at' => now()->toIso8601String()];
        } elseif ($flat) {
            $daysBilled = count($due['days']);
            $billed = 1;
            $amount = $exception === null ? Invoices::lineAmount($unit, 1, $daysBilled, $daysInPeriod, $rounding) : Money::zero($unit->currency);
            $evidence = ['method' => 'flat', 'computed_at' => now()->toIso8601String()];
        } else {
            $daysBilled = count($due['days']);
            $evidence = $this->quantity->peak($tenant, $due['days']);
            $measured = $evidence['peak'];
            $billed = $kind === BillingPeriodKind::MonthlyArrears ? max($measured, $point['minimum']) : max(0, $measured - (int) $term->committed_quantity);
            $amount = $exception === null && $billed > 0 ? Invoices::lineAmount($unit, $billed, $daysBilled, $daysInPeriod, $rounding) : Money::zero($unit->currency);
        }
        $status = $exception !== null ? BillingPeriod::EXCEPTION : ($amount->isZero() ? BillingPeriod::NOTHING_DUE : BillingPeriod::DRAFTED);
        $evidence += ['billable_days' => $daysBilled, 'first_billable_day' => $firstDay, 'last_billable_day' => $lastDay, 'price_source' => $point['source'],
            'price' => $point['label'], 'rounding' => $rounding === RoundingMode::HalfEven ? 'half_even' : 'half_up',
            'calculation' => $this->calculation($kind, $flat, $point, $billed, $measured, $term->committed_quantity, $daysBilled, $daysInPeriod, $amount)];

        try {
            return DB::transaction(function () use ($tenant, $subscription, $term, $point, $kind, $due, $daysInPeriod, $daysBilled, $measured, $billed, $amount, $evidence, $status, $exception) {
                $period = BillingPeriod::query()->create(['subscription_id' => $subscription->id, 'billing_term_id' => $term->id,
                    'plan_price_version_id' => $point['source'] === SubscriptionBillingTerm::STANDARD ? $point['version']->id : null,
                    'negotiated_price_version_id' => $point['source'] === SubscriptionBillingTerm::NEGOTIATED ? $point['version']->id : null, 'price_source' => $point['source'],
                    'plan_version_id' => $term->plan_version_id, 'market_id' => $term->market_id, 'kind' => $kind, 'period_start' => $due['start'], 'period_end' => $due['end'],
                    'days_in_period' => $daysInPeriod, 'days_billed' => $daysBilled, 'currency' => $point['unit']->currency, 'unit_amount_minor' => $point['unit']->minor,
                    'discount_percent' => $point['discount'], 'net_unit_amount_minor' => $point['net']->minor,
                    'minimum_quantity' => $point['minimum'], 'committed_quantity' => $term->committed_quantity, 'measured_peak' => $measured,
                    'billed_quantity' => $billed, 'amount_minor' => $amount->minor, 'evidence' => $evidence, 'status' => $status, 'exception' => $exception]);

                return $this->recorded($tenant, $subscription, $period, $kind, $due, $amount, $billed, $measured, $status, $exception);
            });
        } catch (UniqueConstraintViolationException) {
            return null; // another run calculated it first
        }
    }

    /** A month whose billable days have no terms in force at all: an exception, nothing billed (no price is guessed or borrowed). */
    private function unpriced(Tenant $tenant, TenantSubscription $subscription, array $due, int $daysInPeriod): ?string
    {
        $last = $due['last_term'];
        $state = $due['states'][$due['unpriced'][0]];
        $exception = 'NO_PRICE_CONFIGURED: '.count($due['unpriced']).' billable days ('.$due['unpriced'][0].' to '.end($due['unpriced'])
            .') have no billing terms (no standard or agreed price pinned): pin terms and bill them by hand.';
        $evidence = ['method' => 'no_price_configured', 'unpriced_days' => $due['unpriced'], 'previous_terms' => $last->id, 'computed_at' => now()->toIso8601String()];

        try {
            return DB::transaction(function () use ($tenant, $subscription, $due, $daysInPeriod, $last, $state, $exception, $evidence) {
                $period = BillingPeriod::query()->create(['subscription_id' => $subscription->id, 'billing_term_id' => null, 'plan_price_version_id' => null,
                    'negotiated_price_version_id' => null, 'price_source' => 'none', 'plan_version_id' => $state['plan_version_id'] ?? $last->plan_version_id,
                    'market_id' => $last->market_id, 'kind' => $due['kind'], 'period_start' => $due['start'], 'period_end' => $due['end'], 'days_in_period' => $daysInPeriod,
                    'days_billed' => 0, 'currency' => $last->currency, 'unit_amount_minor' => 0, 'minimum_quantity' => 0, 'billed_quantity' => 0, 'amount_minor' => 0,
                    'evidence' => $evidence, 'status' => BillingPeriod::EXCEPTION, 'exception' => $exception]);

                return $this->recorded($tenant, $subscription, $period, $due['kind'], $due, $period->amount(), 0, null, BillingPeriod::EXCEPTION, $exception);
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function recorded(Tenant $tenant, TenantSubscription $subscription, BillingPeriod $period, BillingPeriodKind $kind, array $due, Money $amount, int $billed,
        ?int $measured, string $status, ?string $exception): string
    {
        $invoice = null;
        if ($status === BillingPeriod::DRAFTED) {
            $invoice = $this->draft($tenant, $period, "billing-period:{$period->id}", "Billing run: {$kind->label()} {$due['start']} to {$due['end']}");
            $period->forceFill(['invoice_id' => $invoice->id])->save();
        }
        $this->audit->both(AuditAction::BillingPeriodCalculated, 'billing', $tenant, $period, $this->label($period),
            [['field' => 'period', 'before' => 'none', 'after' => "{$status}: {$amount->currency->value} {$amount->toDecimal()}"]],
            $exception ?? 'Billing run', null, ['billing_period_id' => $period->id, 'subscription_id' => $subscription->id, 'kind' => $kind->value,
                'price_source' => $period->price_source, 'quantity' => $billed, 'measured_peak' => $measured, 'invoice_reference' => $invoice?->reference, 'trigger' => 'billing_run',
                'idempotency_key' => "{$subscription->id}:{$kind->value}:{$due['start']}"], $due['start']);

        return $status;
    }

    /**
     * The price of a term, from its source (agreed terms or the standard catalogue): the list unit amount, the agreed
     * discount, the net unit (the discount applied once to the unit, with the configured rounding) and the minimum.
     *
     * @return array{source: string, version: PlanPriceVersion|NegotiatedPriceVersion, unit: Money, net: Money, discount: ?string, minimum: int, label: string}
     */
    private function pricePoint(SubscriptionBillingTerm $term, RoundingMode $rounding): array
    {
        $version = $term->pinnedVersion();
        if ($version instanceof NegotiatedPriceVersion) {
            return ['source' => SubscriptionBillingTerm::NEGOTIATED, 'version' => $version, 'unit' => $version->amount(), 'net' => $version->netAmount($rounding),
                'discount' => $version->discount_percent === null ? null : (string) $version->discount_percent, 'minimum' => $version->minimum_quantity,
                'label' => "negotiated v{$version->version}"];
        }

        return ['source' => SubscriptionBillingTerm::STANDARD, 'version' => $version, 'unit' => $version->amount(), 'net' => $version->amount(), 'discount' => null,
            'minimum' => $version->minimum_quantity, 'label' => "standard v{$version->version}"];
    }

    /** The configured rounding of a prorated or discounted amount (B-3: half up), in force on $day. */
    private function rounding(string $day): RoundingMode
    {
        return app(CommercialConfiguration::class)->required(ConfigurationKey::ProrationRounding, '', $day) === 'half_even' ? RoundingMode::HalfEven : RoundingMode::HalfUp;
    }

    private function draft(Tenant $tenant, BillingPeriod $period, string $key, string $reason): Invoice
    {
        $annual = $period->kind === BillingPeriodKind::AnnualAdvance;
        $unit = $annual ? $period->netUnitAmount()->times(12) : $period->netUnitAmount();
        $partial = ! $annual && $period->days_billed < $period->days_in_period;
        $rounding = ($period->evidence['rounding'] ?? 'half_up') === 'half_even' ? RoundingMode::HalfEven : RoundingMode::HalfUp;
        $line = new InvoiceLineInput($this->description($period), $period->billed_quantity, $unit, 'peopleos.subscription', $period->plan_price_version_id,
            $period->plan_version_id, $period->evidence['first_billable_day'] ?? $period->period_start->toDateString(), $period->evidence['last_billable_day'] ?? $period->period_end->toDateString(),
            $partial ? $period->days_billed : null, $partial ? $period->days_in_period : null, $period->id,
            array_diff_key($period->evidence, ['daily_counts' => true, 'employee_ids' => true]) + ['employee_count' => count($period->evidence['employee_ids'] ?? [])],
            $period->negotiated_price_version_id, $rounding);
        $amount = Invoices::lineAmount($line->unitAmount, $line->quantity, $line->daysBilled, $line->daysInPeriod, $rounding);
        if ($amount->minor !== $period->amount_minor) {
            throw new RuntimeException("Billing period {$period->id}: the invoice line would not equal the calculated amount.");
        }

        return $this->invoices->draftForPeriod($tenant, BillingMarket::query()->findOrFail($period->market_id), $line,
            TenantSubscription::query()->findOrFail($period->subscription_id), $period->period_start->toDateString(), $period->period_end->toDateString(), $key, $reason);
    }

    private function description(BillingPeriod $period): string
    {
        $plan = $this->catalog->planLabel($period->plan_version_id);
        $unit = "{$period->currency->value} {$period->netUnitAmount()->toDecimal()}";
        $agreed = $period->price_source === SubscriptionBillingTerm::NEGOTIATED ? ' (agreed price'
            .($period->discount_percent !== null ? ": {$period->currency->value} {$period->unitAmount()->toDecimal()} less {$period->discount_percent} %" : '').')' : '';
        $days = $period->days_billed < $period->days_in_period ? " × {$period->days_billed}/{$period->days_in_period} days" : '';
        $month = $period->period_start->format('F Y');
        $peak = $period->evidence['peak_day'] ?? null;
        $flat = ($period->evidence['method'] ?? null) === 'flat';

        return mb_substr(match (true) {
            $flat && $period->kind === BillingPeriodKind::AnnualAdvance => "{$plan} · annual term {$period->period_start->toDateString()} to {$period->period_end->toDateString()}"
                ." · fixed {$unit} per month{$agreed} × 12 months",
            $flat => "{$plan} · {$month} · fixed {$unit} per month{$agreed}{$days}",
            $period->kind === BillingPeriodKind::MonthlyArrears => "{$plan} · {$month} · peak {$period->measured_peak} employees on {$peak}"
                .($period->minimum_quantity > $period->measured_peak ? " (minimum {$period->minimum_quantity})" : '')." · {$period->billed_quantity} × {$unit} per employee per month{$agreed}{$days}",
            $period->kind === BillingPeriodKind::AnnualTrueUp => "{$plan} · true-up {$month} · peak {$period->measured_peak} on {$peak} above the commitment of {$period->committed_quantity}"
                ." · {$period->billed_quantity} × {$unit} per employee per month{$agreed}{$days}",
            default => "{$plan} · annual term {$period->period_start->toDateString()} to {$period->period_end->toDateString()}"
                ." · {$period->billed_quantity} committed employees × {$unit} per employee per month{$agreed} × 12 months",
        }, 0, 300);
    }

    /** @param  array{unit: Money, net: Money, discount: ?string, minimum: int}  $point */
    private function calculation(BillingPeriodKind $kind, bool $flat, array $point, int $billed, ?int $measured, ?int $committed, int $days, int $daysIn, Money $amount): string
    {
        $u = "{$point['net']->currency->value} {$point['net']->toDecimal()}".($point['discount'] !== null ? " ({$point['unit']->toDecimal()} less {$point['discount']} %)" : '');
        $prorate = $days < $daysIn ? " × {$days}/{$daysIn}" : '';

        return match (true) {
            $flat && $kind === BillingPeriodKind::AnnualAdvance => "fixed {$u} × 12 = {$amount->toDecimal()}",
            $flat => "fixed {$u}{$prorate} = {$amount->toDecimal()}",
            $kind === BillingPeriodKind::MonthlyArrears => "max(peak {$measured}, minimum {$point['minimum']}) = {$billed} × {$u}{$prorate} = {$amount->toDecimal()}",
            $kind === BillingPeriodKind::AnnualTrueUp => "max(0, peak {$measured} − committed {$committed}) = {$billed} × {$u}{$prorate} = {$amount->toDecimal()}",
            default => "committed {$billed} × {$u} × 12 = {$amount->toDecimal()}",
        };
    }

    private function label(BillingPeriod $period): string
    {
        return "billing period {$period->kind->label()} {$period->period_start->toDateString()} to {$period->period_end->toDateString()}";
    }
}
