<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoiceNumberSeries;
use App\Domain\Billing\Models\InvoiceTaxLine;
use App\Domain\Billing\Models\InvoiceTdsClaim;
use App\Domain\Billing\Models\NegotiatedPriceVersion;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\SupplierProfile;
use App\Domain\Billing\Models\TenantBillingProfile;
use App\Domain\Billing\Support\InvoiceLineInput;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Domain\Tax\Enums\TaxRegistration;
use App\Domain\Tax\Exceptions\TaxUnavailableException;
use App\Domain\Tax\Services\TaxEngine;
use App\Domain\Tax\Support\TaxCalculation;
use App\Domain\Tax\Support\TaxContext;
use App\Domain\Tax\Support\TaxJurisdiction;
use App\Domain\Tax\Support\TaxParty;
use App\Domain\Tax\Support\TaxQuote;
use App\Support\Commercial\OperatorChange;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * SaaS.7: invoices of a tenant. A draft holds priced lines in its market's currency (the billing run drafts them
 * from calculated billing periods; no page drafts invoices by hand). Issue fixes everything in one transaction under
 * the invoice and number-series locks: the customer and supplier profiles in force, the tax from the
 * jurisdiction-neutral engine (refused when it cannot be determined safely), the gap-free number, the totals, the
 * due date (net 15 by default, B-11) and the snapshots. An issued invoice never changes again except to record its
 * settlement: paid, partially paid while a TDS certificate is pending, credited or written off. The amount due is
 * the total less issued credit notes and declared customer TDS.
 */
final class Invoices
{
    public function __construct(private readonly BillingAudit $audit, private readonly BillingProfiles $profiles, private readonly SupplierProfiles $suppliers,
        private readonly InvoiceSeries $series, private readonly TaxEngine $tax, private readonly TenantContext $tenants,
        private readonly FinancialApprovals $approvals, private readonly CommercialConfiguration $configuration) {}

    /** @param  list<InvoiceLineInput>  $lines */
    public function draft(Tenant $tenant, BillingMarket $market, array $lines, string $reason, User $actor, ?TenantSubscription $subscription = null,
        ?string $periodStart = null, ?string $periodEnd = null, ?string $idempotencyKey = null): Invoice
    {
        OperatorChange::assert($actor, $reason, 'invoices');

        return $this->create($tenant, $market, $lines, $reason, $actor, $subscription, $periodStart, $periodEnd, $idempotencyKey);
    }

    /**
     * The billing run's draft of one calculated period (system actor). Runs inside the run's transaction, so the
     * period and its draft are created together or not at all.
     */
    public function draftForPeriod(Tenant $tenant, BillingMarket $market, InvoiceLineInput $line, TenantSubscription $subscription, string $periodStart,
        string $periodEnd, string $idempotencyKey, string $reason): Invoice
    {
        if (DB::transactionLevel() === 0 || $line->billingPeriodId === null) {
            throw new RuntimeException('A period invoice is drafted only by the billing run, inside its transaction.');
        }

        return $this->create($tenant, $market, [$line], $reason, null, $subscription, $periodStart, $periodEnd, $idempotencyKey);
    }

    /** @param  list<InvoiceLineInput>  $lines */
    private function create(Tenant $tenant, BillingMarket $market, array $lines, string $reason, ?User $actor, ?TenantSubscription $subscription,
        ?string $periodStart, ?string $periodEnd, ?string $idempotencyKey): Invoice
    {
        if ($lines === [] || count($lines) > 200) {
            throw new RuntimeException('An invoice has 1 to 200 lines.');
        }
        if ($subscription !== null && $subscription->tenant_id !== $tenant->id) {
            throw new RuntimeException('The subscription belongs to another tenant.');
        }
        [$periodStart, $periodEnd] = $this->period($periodStart, $periodEnd);
        $categories = array_values(array_unique(array_map(fn (InvoiceLineInput $l) => $l->taxCategory, $lines)));
        if (count($categories) !== 1) {
            throw new RuntimeException('All lines of an invoice share one tax category.');
        }
        $rows = [];
        $subtotal = Money::zero($market->currency);
        foreach (array_values($lines) as $i => $line) {
            $rows[] = $this->line($market, $line, $i + 1);
            $subtotal = $subtotal->plus(Money::ofMinor($rows[$i]['amount_minor'], $market->currency));
        }
        $key = $idempotencyKey === null ? null : mb_substr(trim($idempotencyKey), 0, 100);

        return $this->tenants->runAs($tenant, function () use ($tenant, $market, $rows, $subtotal, $reason, $actor, $subscription, $periodStart, $periodEnd, $key) {
            if ($key !== null && ($existing = Invoice::query()->where('idempotency_key', $key)->first()) !== null) {
                return $existing;
            }
            try {
                return DB::transaction(function () use ($tenant, $market, $rows, $subtotal, $reason, $actor, $subscription, $periodStart, $periodEnd, $key) {
                    $invoice = Invoice::query()->create(['status' => InvoiceStatus::Draft, 'market_id' => $market->id, 'supplier_entity' => $market->supplier_entity,
                        'currency' => $market->currency, 'subscription_id' => $subscription?->id, 'period_start' => $periodStart, 'period_end' => $periodEnd,
                        'subtotal_minor' => $subtotal->minor, 'tax_minor' => 0, 'total_minor' => $subtotal->minor, 'idempotency_key' => $key, 'reason' => $reason,
                        'created_by' => $actor?->id]);
                    foreach ($rows as $row) {
                        InvoiceLine::query()->create($row + ['invoice_id' => $invoice->id]);
                    }
                    $this->audit->both(AuditAction::InvoiceDrafted, 'billing', $tenant, $invoice, "invoice {$invoice->label()}",
                        [['field' => 'status', 'before' => 'none', 'after' => 'draft'], ['field' => 'subtotal', 'before' => null, 'after' => "{$subtotal->currency->value} {$subtotal->toDecimal()}"]],
                        $reason, $actor, ['invoice_reference' => $invoice->reference, 'market' => $market->code, 'lines' => count($rows), 'idempotency_key' => $key]
                            + ($actor === null ? ['trigger' => 'billing_run'] : []));

                    return $invoice;
                });
            } catch (UniqueConstraintViolationException) {
                return Invoice::query()->where('idempotency_key', $key)->first()
                    ?? throw new RuntimeException('That idempotency key is already used.');
            }
        });
    }

    /**
     * @param  array{rate?: ?string, source?: ?string, date?: ?string}|null  $reporting  the rate the law requires the value to be reported at when
     *                                                                                   the invoice is in another currency (India: INR at the accounting rate for the date of supply, CGST Rules rule 34(2)); a reporting
     *                                                                                   value only: the invoice's amounts and currency never change.
     */
    public function issue(Invoice $invoice, ?string $dueDate, string $reason, User $actor, ?array $reporting = null): Invoice
    {
        OperatorChange::assert($actor, $reason, 'invoices');
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);

        return $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $invoice, $dueDate, $reason, $actor, $reporting) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status->isIssuedDocument()) {
                return $locked; // issuing twice changes nothing
            }
            if ($locked->status === InvoiceStatus::Discarded) {
                throw new RuntimeException('A discarded draft cannot be issued.');
            }
            $today = now()->toDateString();
            if ($this->configuration->required(ConfigurationKey::PricesIncludeTax, '', $today) === true) {
                throw new RuntimeException('Markedge policy says prices include tax, but tax-inclusive invoicing is not enabled: change the policy or wait for that release.');
            }
            // The configured payment terms (B-11: net 15) from the issue date, unless the operator gives another date.
            $due = $dueDate === null || trim($dueDate) === '' ? now()->addDays((int) $this->configuration->required(ConfigurationKey::PaymentTermsDays, '', $today))->toDateString() : $this->day($dueDate);
            if ($due < $today) {
                throw new RuntimeException('The due date is on or after the issue date.');
            }
            [$profile, $supplier, $quote] = $this->prepare($tenant, $locked, $today);
            $lines = InvoiceLine::query()->where('invoice_id', $locked->id)->orderBy('line_no')->get();
            $taxable = $lines->mapWithKeys(fn (InvoiceLine $l) => [$l->line_no => $l->amount()])->all();
            $legs = $this->tax->calculateLegs($quote, $locked->currency, $taxable);
            $taxTotal = Money::zero($locked->currency);
            $byType = [];
            foreach ($legs as ['calculation' => $calculation]) {
                $taxTotal = $taxTotal->plus($calculation->total);
                foreach ($calculation->totalsByType() as $type => $amount) {
                    $byType[$type] = ($byType[$type] ?? 0) + $amount->minor;
                }
            }
            $total = $locked->subtotal()->plus($taxTotal);
            $reportingValue = $quote->reportingCurrency === null ? null : $this->reportingValue($quote->reportingCurrency, $locked->subtotal(), $taxTotal, $total, $reporting, $today);
            [$series, $sequence, $number] = $this->series->allocate($locked->supplier_entity, $today);
            foreach ($legs as ['leg' => $leg, 'calculation' => $calculation]) {
                foreach ($calculation->lines as $taxLine) {
                    InvoiceTaxLine::query()->create(['invoice_id' => $locked->id, 'line_no' => $taxLine->lineNo, 'regime' => $leg->determination->regime,
                        'country' => $leg->determination->placeOfSupply->country, 'subdivision' => $leg->determination->placeOfSupply->subdivision,
                        'tax_type' => $taxLine->type, 'treatment' => $leg->treatment, 'rate' => $taxLine->rate, 'taxable_minor' => $taxLine->taxable->minor,
                        'tax_minor' => $taxLine->tax->minor, 'currency' => $locked->currency, 'tax_rule_id' => $leg->rule->id, 'metadata' => ['leg' => $leg->role]]);
                }
            }
            $calculation = new TaxCalculation([], $taxTotal);
            $market = BillingMarket::query()->findOrFail($locked->market_id);
            $locked->forceFill(['status' => InvoiceStatus::Issued, 'series_id' => $series->id, 'sequence' => $sequence, 'number' => $number, 'issue_date' => $today,
                'due_date' => $due, 'tax_minor' => $calculation->total->minor, 'total_minor' => $total->minor, 'tax_regime' => $quote->determination->regime->value,
                'tax_treatment' => $quote->determination->treatment->value, 'billing_profile_id' => $profile->id, 'supplier_profile_id' => $supplier->id,
                'tax_rule_id' => $quote->rule->id, 'issued_by' => $actor->id, 'issued_at' => now(),
                'snapshot' => ['market' => ['code' => $market->code, 'name' => $market->name, 'locale' => $market->locale, 'currency' => $locked->currency->value],
                    'supplier' => $supplier->snapshot(), 'customer' => $profile->snapshot(),
                    'tax' => $quote->snapshot() + ['totals_by_type' => $byType], 'reporting' => $reportingValue]])->save();
            $this->audit->both(AuditAction::InvoiceIssued, 'billing', $tenant, $locked, "invoice {$number}", [
                ['field' => 'status', 'before' => 'draft', 'after' => 'issued'], ['field' => 'number', 'before' => null, 'after' => $number],
                ['field' => 'total', 'before' => null, 'after' => "{$total->currency->value} {$total->toDecimal()}"],
                ['field' => 'tax', 'before' => null, 'after' => "{$calculation->total->currency->value} {$calculation->total->toDecimal()} ({$quote->determination->regime->value}, {$quote->determination->outcome})"],
            ], $reason, $actor, ['invoice_reference' => $locked->reference, 'number' => $number, 'series_id' => $series->id, 'tax_rule_id' => $quote->rule->id,
                'tax_rule_ids' => array_map(fn ($l) => $l['leg']->rule->id, $legs), 'billing_profile_id' => $profile->id, 'supplier_profile_id' => $supplier->id,
                'reporting' => $reportingValue, 'idempotency_key' => $locked->reference], $today);

            return $locked;
        }));
    }

    public function discard(Invoice $invoice, string $reason, User $actor): Invoice
    {
        OperatorChange::assert($actor, $reason, 'invoices');
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);

        return $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $invoice, $reason, $actor) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status === InvoiceStatus::Discarded) {
                return $locked;
            }
            if ($locked->status !== InvoiceStatus::Draft) {
                throw new RuntimeException("An {$locked->status->value} invoice cannot be discarded: correct it with a credit note.");
            }
            $locked->forceFill(['status' => InvoiceStatus::Discarded, 'discarded_by' => $actor->id, 'discarded_at' => now(), 'discard_reason' => $reason])->save();
            $this->audit->both(AuditAction::InvoiceDiscarded, 'billing', $tenant, $locked, "invoice {$locked->label()}",
                [['field' => 'status', 'before' => 'draft', 'after' => 'discarded']], $reason, $actor, ['invoice_reference' => $locked->reference]);

            return $locked;
        }));
    }

    /**
     * Whether a draft could be issued today, and why not: the same checks issue runs, without writing anything.
     *
     * @return array{ready: bool, problems: list<string>, quote: ?TaxQuote}
     */
    public function readiness(Invoice $invoice): array
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            return ['ready' => false, 'problems' => ["The invoice is {$invoice->status->value}."], 'quote' => null];
        }
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);
        try {
            [, , $quote] = $this->tenants->runAs($tenant, fn () => $this->prepare($tenant, $invoice, now()->toDateString()));
        } catch (RuntimeException $e) {
            return ['ready' => false, 'problems' => [$e->getMessage()], 'quote' => null];
        }
        $series = InvoiceNumberSeries::query()->where(['supplier_entity' => $invoice->supplier_entity, 'document_type' => 'invoice', 'status' => 'open'])
            ->whereDate('starts_on', '<=', now()->toDateString())->whereDate('ends_on', '>=', now()->toDateString())->exists();

        return ['ready' => $series, 'problems' => $series ? [] : ["No open invoice number series of {$invoice->supplier_entity} covers today."], 'quote' => $quote];
    }

    /**
     * What is still to be paid on an issued invoice: the total less its issued credit notes and the customer TDS
     * declared on it (B-11, B-12). Read inside the invoice's tenant.
     */
    public function amountDue(Invoice $invoice): Money
    {
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);

        // Locking reads: under MySQL's repeatable read, a plain read after waiting for the invoice lock could miss a credit
        // note or TDS committed meanwhile.
        return $this->tenants->runAs($tenant, function () use ($invoice) {
            $credited = (int) CreditNote::query()->where('invoice_id', $invoice->id)->sharedLock()->sum('total_minor');
            $tds = (int) InvoiceTdsClaim::query()->where('invoice_id', $invoice->id)->sharedLock()->sum('amount_minor');

            return $invoice->total()->minus(Money::ofMinor($credited, $invoice->currency))->minus(Money::ofMinor($tds, $invoice->currency));
        });
    }

    /**
     * Records that a locked, open invoice is settled by $paymentId: paid, or partially paid while the declared TDS
     * awaits its certificate. Called by payment reconciliation and TDS certification inside their transaction.
     */
    public function markPaid(Invoice $locked, ?int $paymentId, ?User $actor, string $trigger, string $reason, ?string $correlation, bool $tdsPending = false): Invoice
    {
        if (DB::transactionLevel() === 0 || ! $locked->status->isOpen()) {
            throw new RuntimeException('Only an open invoice, locked in the reconciling transaction, can be marked paid.');
        }
        $before = $locked->status->value;
        $after = $tdsPending ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Paid;
        if ($before === $after->value) {
            return $locked;
        }
        $locked->forceFill(['status' => $after] + ($tdsPending ? [] : ['paid_at' => now()]) + ($paymentId !== null && $locked->paid_by_payment_id === null ? ['paid_by_payment_id' => $paymentId] : []))->save();
        $this->audit->both($tdsPending ? AuditAction::InvoicePartiallyPaid : AuditAction::InvoicePaid, 'billing', Tenant::query()->findOrFail($locked->tenant_id), $locked,
            "invoice {$locked->number}", [['field' => 'status', 'before' => $before, 'after' => $after->value]], $reason, $actor,
            ['invoice_reference' => $locked->reference, 'payment_id' => $paymentId ?? $locked->paid_by_payment_id, 'trigger' => $trigger, 'correlation_id' => $correlation]);

        return $locked;
    }

    /** Asks for an unpaid invoice to be written off (B-13: executed only when another operator approves it). */
    public function requestWriteOff(Invoice $invoice, string $reason, User $maker): FinancialApproval
    {
        OperatorChange::assert($maker, $reason, 'invoices');
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);
        $invoice = $this->tenants->runAs($tenant, fn () => Invoice::query()->findOrFail($invoice->id));
        if (! $invoice->status->isOpen()) {
            throw new RuntimeException("Only an unpaid issued invoice can be written off; {$invoice->label()} is {$invoice->status->value}.");
        }
        $due = $this->amountDue($invoice);

        return $this->approvals->request(ApprovalAction::InvoiceWriteOff, $invoice, $tenant, ['invoice_reference' => $invoice->reference, 'invoice_number' => $invoice->number,
            'tenant' => $tenant->name, 'amount_due' => "{$due->currency->value} {$due->toDecimal()}"],
            ['status' => $invoice->status->value, 'amount_due' => "{$due->currency->value} {$due->toDecimal()}"], ['status' => 'written_off', 'amount_due' => "{$due->currency->value} 0"],
            "write_off:{$invoice->reference}", $reason, $maker);
    }

    /** Executes an approved write-off (called by the approval desk, inside its transaction). */
    public function executeWriteOff(FinancialApproval $approval): Invoice
    {
        $approval = $this->approvals->claim($approval, ApprovalAction::InvoiceWriteOff);
        $tenant = Tenant::query()->findOrFail($approval->subject_tenant_id);

        return $this->tenants->runAs($tenant, function () use ($tenant, $approval) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($approval->subject_id);
            if (! $locked->status->isOpen()) {
                throw new RuntimeException("Invoice {$locked->label()} is {$locked->status->value} now: it can no longer be written off.");
            }
            $before = $locked->status->value;
            $locked->forceFill(['status' => InvoiceStatus::WrittenOff, 'closed_at' => now(), 'closure_approval_id' => $approval->id])->save();
            $checker = User::query()->findOrFail($approval->checker_id);
            $this->audit->both(AuditAction::InvoiceWrittenOff, 'billing', $tenant, $locked, "invoice {$locked->number}",
                [['field' => 'status', 'before' => $before, 'after' => 'written_off']], (string) $approval->checker_reason, $checker,
                ['invoice_reference' => $locked->reference, 'approval' => $approval->reference, 'maker_id' => $approval->maker_id, 'checker_id' => $approval->checker_id,
                    'correlation_id' => $approval->correlation_key]);
            $this->approvals->executed($approval, "Invoice {$locked->number} written off");

            return $locked;
        });
    }

    /**
     * The invoice value in the reporting currency the law requires, at the rate the operator records with its source and
     * date (never fetched or guessed). Converted once, half up, for reporting; nothing else uses it.
     *
     * @param  array{rate?: ?string, source?: ?string, date?: ?string}|null  $reporting
     * @return array{currency: string, rate: string, source: string, date: string, subtotal_minor: int, tax_minor: int, total_minor: int}
     */
    private function reportingValue(string $currency, Money $subtotal, Money $tax, Money $total, ?array $reporting, string $today): array
    {
        $rate = trim((string) ($reporting['rate'] ?? ''));
        $source = trim((string) ($reporting['source'] ?? ''));
        if (preg_match('/^\d{1,6}(\.\d{1,8})?$/', $rate) !== 1 || ! BigDecimal::of($rate)->isPositive() || $source === '') {
            throw new RuntimeException("[REPORTING_VALUE_REQUIRED] The law requires this invoice's value in {$currency}: give the rate for the date of supply and its source (e.g. the accounting rate used).");
        }
        $date = blank($reporting['date'] ?? null) ? $today : $this->day((string) $reporting['date']);
        $target = Currency::of($currency);
        $convert = fn (Money $m) => BigDecimal::ofUnscaledValue($m->minor, $m->currency->minorUnits())->multipliedBy($rate)
            ->toScale($target->minorUnits(), RoundingMode::HalfUp)->withPointMovedRight($target->minorUnits())->toBigInteger()->toInt();

        return ['currency' => $currency, 'rate' => $rate, 'source' => mb_substr($source, 0, 150), 'date' => $date,
            'subtotal_minor' => $convert($subtotal), 'tax_minor' => $convert($tax), 'total_minor' => $convert($total)];
    }

    /** @return array{0: TenantBillingProfile, 1: SupplierProfile, 2: TaxQuote} */
    private function prepare(Tenant $tenant, Invoice $invoice, string $today): array
    {
        $profile = $this->profiles->inForce($tenant, $today)
            ?? throw new RuntimeException("{$tenant->name} has no billing profile in force: it cannot be invoiced.");
        if ($profile->market_id !== $invoice->market_id) {
            throw new RuntimeException("{$tenant->name} is billed in {$profile->market->code} now; this draft is for another market.");
        }
        $supplier = $this->suppliers->inForce($invoice->supplier_entity, $today)
            ?? throw new RuntimeException("No supplier profile of {$invoice->supplier_entity} is in force.");
        $category = (string) InvoiceLine::query()->where('invoice_id', $invoice->id)->value('tax_category');
        $context = new TaxContext(
            new TaxParty(new TaxJurisdiction($supplier->country, $supplier->subdivision), $supplier->tax_id_value === null ? TaxRegistration::Unregistered : TaxRegistration::Registered,
                $supplier->tax_id_type, $supplier->tax_id_value, registrations: $supplier->registrations ?? []),
            new TaxParty(new TaxJurisdiction($profile->country, $profile->subdivision), $profile->tax_registration, $profile->tax_id_type, $profile->tax_id_value,
                $profile->customer_type, $profile->special_tax_status),
            $category, $today, $invoice->currency);
        try {
            return [$profile, $supplier, $this->tax->quote($context)];
        } catch (TaxUnavailableException $e) {
            throw new RuntimeException("Tax cannot be determined, so the invoice cannot be issued: {$e->getMessage()}");
        }
    }

    /** @return array<string, mixed> */
    private function line(BillingMarket $market, InvoiceLineInput $line, int $number): array
    {
        $description = trim($line->description);
        if ($description === '' || mb_strlen($description) > 300) {
            throw new RuntimeException('Each line needs a description of at most 300 characters.');
        }
        if ($line->unitAmount->currency !== $market->currency) {
            throw new RuntimeException("Line {$number} is in {$line->unitAmount->currency->value}; the {$market->code} market bills in {$market->currency->value}.");
        }
        if ($line->quantity < 1 || $line->unitAmount->isNegative()) {
            throw new RuntimeException("Line {$number} needs a whole quantity of at least 1 and an amount of zero or more.");
        }
        if (preg_match('/^[a-z0-9._-]{2,64}$/', $line->taxCategory) !== 1) {
            throw new RuntimeException("Line {$number} has no valid tax category.");
        }
        if ($line->planPriceVersionId !== null && $line->negotiatedPriceVersionId !== null) {
            throw new RuntimeException("Line {$number} is priced from one source: a standard or a negotiated price.");
        }
        if ($line->planPriceVersionId !== null) {
            $version = PlanPriceVersion::query()->with('price')->findOrFail($line->planPriceVersionId);
            if ($version->price->market_id !== $market->id || $version->currency !== $market->currency) {
                throw new RuntimeException("Line {$number} refers to a price of another market.");
            }
        }
        if ($line->negotiatedPriceVersionId !== null) {
            $agreed = NegotiatedPriceVersion::query()->with('negotiatedPrice')->findOrFail($line->negotiatedPriceVersionId);
            if ($agreed->negotiatedPrice->market_id !== $market->id || $agreed->currency !== $market->currency) {
                throw new RuntimeException("Line {$number} refers to a negotiated price of another market.");
            }
        }
        if (! in_array($line->rounding, [RoundingMode::HalfUp, RoundingMode::HalfEven], true)) {
            throw new RuntimeException("Line {$number}: a prorated amount is rounded half up or half even.");
        }
        [$start, $end] = $this->period($line->periodStart, $line->periodEnd);
        if (($line->daysBilled === null) !== ($line->daysInPeriod === null)) {
            throw new RuntimeException("Line {$number} gives the days billed and the days in the period together.");
        }
        try {
            $amount = self::lineAmount($line->unitAmount, $line->quantity, $line->daysBilled, $line->daysInPeriod, $line->rounding);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }

        return ['line_no' => $number, 'description' => $description, 'tax_category' => $line->taxCategory, 'quantity' => $line->quantity,
            'unit_amount_minor' => $line->unitAmount->minor, 'amount_minor' => $amount->minor, 'currency' => $market->currency,
            'plan_price_version_id' => $line->planPriceVersionId, 'negotiated_price_version_id' => $line->negotiatedPriceVersionId, 'plan_version_id' => $line->planVersionId,
            'period_start' => $start, 'period_end' => $end,
            'billing_period_id' => $line->billingPeriodId, 'days_billed' => $line->daysBilled, 'days_in_period' => $line->daysInPeriod,
            'quantity_evidence' => $line->quantityEvidence];
    }

    /** B-3: quantity × unit, and for a partial month × days billed ÷ days in the month, rounded once (the configured mode, B-3: half up) to the minor unit. */
    public static function lineAmount(Money $unit, int $quantity, ?int $daysBilled = null, ?int $daysInPeriod = null, RoundingMode $mode = RoundingMode::HalfUp): Money
    {
        $full = $unit->times($quantity);

        return $daysBilled === null || $daysInPeriod === null ? $full : $full->prorated($daysBilled, $daysInPeriod, $mode);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function period(?string $start, ?string $end): array
    {
        if (($start === null) !== ($end === null)) {
            throw new RuntimeException('A service period has both a start and an end.');
        }
        if ($start === null) {
            return [null, null];
        }
        [$start, $end] = [$this->day($start), $this->day($end)];
        if ($end < $start) {
            throw new RuntimeException('A service period ends on or after its start.');
        }

        return [$start, $end];
    }

    private function day(string $day): string
    {
        try {
            return Carbon::createFromFormat('!Y-m-d', $day)->toDateString();
        } catch (\Throwable) {
            throw new RuntimeException("{$day} is not a date (YYYY-MM-DD).");
        }
    }
}
