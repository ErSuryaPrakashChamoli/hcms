<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoiceTaxLine;
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
use App\Domain\Tax\Support\TaxContext;
use App\Domain\Tax\Support\TaxJurisdiction;
use App\Domain\Tax\Support\TaxParty;
use App\Domain\Tax\Support\TaxQuote;
use App\Support\Commercial\OperatorChange;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * SaaS.7: invoices of a tenant. A draft holds priced lines in its market's currency (the billing calculation will
 * hand them over once decisions B-1 to B-3 exist; no page drafts invoices by hand). Issue fixes everything in one
 * transaction under the invoice and number-series locks: the customer and supplier profiles in force, the tax from
 * the jurisdiction-neutral engine (refused when it cannot be determined safely), the gap-free number, the totals
 * and the snapshots. An issued invoice never changes again except to record that it was paid.
 */
final class Invoices
{
    public function __construct(private readonly BillingAudit $audit, private readonly BillingProfiles $profiles, private readonly SupplierProfiles $suppliers,
        private readonly InvoiceSeries $series, private readonly TaxEngine $tax, private readonly TenantContext $tenants) {}

    /** @param  list<InvoiceLineInput>  $lines */
    public function draft(Tenant $tenant, BillingMarket $market, array $lines, string $reason, User $actor, ?TenantSubscription $subscription = null,
        ?string $periodStart = null, ?string $periodEnd = null, ?string $idempotencyKey = null): Invoice
    {
        OperatorChange::assert($actor, $reason, 'invoices');
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
                        'created_by' => $actor->id]);
                    foreach ($rows as $row) {
                        InvoiceLine::query()->create($row + ['invoice_id' => $invoice->id]);
                    }
                    $this->audit->both(AuditAction::InvoiceDrafted, 'billing', $tenant, $invoice, "invoice {$invoice->label()}",
                        [['field' => 'status', 'before' => 'none', 'after' => 'draft'], ['field' => 'subtotal', 'before' => null, 'after' => "{$subtotal->currency->value} {$subtotal->toDecimal()}"]],
                        $reason, $actor, ['invoice_reference' => $invoice->reference, 'market' => $market->code, 'lines' => count($rows), 'idempotency_key' => $key]);

                    return $invoice;
                });
            } catch (UniqueConstraintViolationException) {
                return Invoice::query()->where('idempotency_key', $key)->first()
                    ?? throw new RuntimeException('That idempotency key is already used.');
            }
        });
    }

    public function issue(Invoice $invoice, ?string $dueDate, string $reason, User $actor): Invoice
    {
        OperatorChange::assert($actor, $reason, 'invoices');
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);

        return $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $invoice, $dueDate, $reason, $actor) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (in_array($locked->status, [InvoiceStatus::Issued, InvoiceStatus::Paid], true)) {
                return $locked; // issuing twice changes nothing
            }
            if ($locked->status === InvoiceStatus::Discarded) {
                throw new RuntimeException('A discarded draft cannot be issued.');
            }
            $today = now()->toDateString();
            $due = $dueDate === null || trim($dueDate) === '' ? null : $this->day($dueDate);
            if ($due !== null && $due < $today) {
                throw new RuntimeException('The due date is on or after the issue date.');
            }
            [$profile, $supplier, $quote] = $this->prepare($tenant, $locked, $today);
            $lines = InvoiceLine::query()->where('invoice_id', $locked->id)->orderBy('line_no')->get();
            $calculation = $this->tax->calculate($quote, $locked->currency, $lines->mapWithKeys(fn (InvoiceLine $l) => [$l->line_no => $l->amount()])->all());
            [$series, $sequence, $number] = $this->series->allocate($locked->supplier_entity, $today);
            foreach ($calculation->lines as $taxLine) {
                InvoiceTaxLine::query()->create(['invoice_id' => $locked->id, 'line_no' => $taxLine->lineNo, 'regime' => $quote->determination->regime,
                    'country' => $quote->determination->placeOfSupply->country, 'subdivision' => $quote->determination->placeOfSupply->subdivision,
                    'tax_type' => $taxLine->type, 'treatment' => $quote->determination->treatment, 'rate' => $taxLine->rate, 'taxable_minor' => $taxLine->taxable->minor,
                    'tax_minor' => $taxLine->tax->minor, 'currency' => $locked->currency, 'tax_rule_id' => $quote->rule->id]);
            }
            $total = $locked->subtotal()->plus($calculation->total);
            $market = BillingMarket::query()->findOrFail($locked->market_id);
            $locked->forceFill(['status' => InvoiceStatus::Issued, 'series_id' => $series->id, 'sequence' => $sequence, 'number' => $number, 'issue_date' => $today,
                'due_date' => $due, 'tax_minor' => $calculation->total->minor, 'total_minor' => $total->minor, 'tax_regime' => $quote->determination->regime->value,
                'tax_treatment' => $quote->determination->treatment->value, 'billing_profile_id' => $profile->id, 'supplier_profile_id' => $supplier->id,
                'tax_rule_id' => $quote->rule->id, 'issued_by' => $actor->id, 'issued_at' => now(),
                'snapshot' => ['market' => ['code' => $market->code, 'name' => $market->name, 'locale' => $market->locale, 'currency' => $locked->currency->value],
                    'supplier' => $supplier->snapshot(), 'customer' => $profile->snapshot(),
                    'tax' => $quote->snapshot() + ['totals_by_type' => array_map(fn (Money $m) => $m->minor, $calculation->totalsByType())]]])->save();
            $this->audit->both(AuditAction::InvoiceIssued, 'billing', $tenant, $locked, "invoice {$number}", [
                ['field' => 'status', 'before' => 'draft', 'after' => 'issued'], ['field' => 'number', 'before' => null, 'after' => $number],
                ['field' => 'total', 'before' => null, 'after' => "{$total->currency->value} {$total->toDecimal()}"],
                ['field' => 'tax', 'before' => null, 'after' => "{$calculation->total->currency->value} {$calculation->total->toDecimal()} ({$quote->determination->regime->value}, {$quote->determination->outcome})"],
            ], $reason, $actor, ['invoice_reference' => $locked->reference, 'number' => $number, 'series_id' => $series->id, 'tax_rule_id' => $quote->rule->id,
                'billing_profile_id' => $profile->id, 'supplier_profile_id' => $supplier->id, 'idempotency_key' => $locked->reference], $today);

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
                throw new RuntimeException("An {$locked->status->value} invoice cannot be discarded: only a credit note could correct it (not available).");
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
        $series = \App\Domain\Billing\Models\InvoiceNumberSeries::query()->where(['supplier_entity' => $invoice->supplier_entity, 'status' => 'open'])
            ->whereDate('starts_on', '<=', now()->toDateString())->whereDate('ends_on', '>=', now()->toDateString())->exists();

        return ['ready' => $series, 'problems' => $series ? [] : ["No open invoice number series of {$invoice->supplier_entity} covers today."], 'quote' => $quote];
    }

    /** Records that a locked, issued invoice is paid by $paymentId. Called by payment reconciliation inside its transaction. */
    public function markPaid(Invoice $locked, int $paymentId, ?User $actor, string $trigger, string $reason, ?string $correlation): Invoice
    {
        if (DB::transactionLevel() === 0 || $locked->status !== InvoiceStatus::Issued) {
            throw new RuntimeException('Only an issued invoice, locked in the reconciling transaction, can be marked paid.');
        }
        $locked->forceFill(['status' => InvoiceStatus::Paid, 'paid_at' => now(), 'paid_by_payment_id' => $paymentId])->save();
        $this->audit->both(AuditAction::InvoicePaid, 'billing', Tenant::query()->findOrFail($locked->tenant_id), $locked, "invoice {$locked->number}",
            [['field' => 'status', 'before' => 'issued', 'after' => 'paid']], $reason, $actor,
            ['invoice_reference' => $locked->reference, 'payment_id' => $paymentId, 'trigger' => $trigger, 'correlation_id' => $correlation]);

        return $locked;
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
                $supplier->tax_id_type, $supplier->tax_id_value),
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
        if ($line->planPriceVersionId !== null) {
            $version = PlanPriceVersion::query()->with('price')->findOrFail($line->planPriceVersionId);
            if ($version->price->market_id !== $market->id || $version->currency !== $market->currency) {
                throw new RuntimeException("Line {$number} refers to a price of another market.");
            }
        }
        [$start, $end] = $this->period($line->periodStart, $line->periodEnd);
        try {
            $amount = $line->unitAmount->times($line->quantity);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }

        return ['line_no' => $number, 'description' => $description, 'tax_category' => $line->taxCategory, 'quantity' => $line->quantity,
            'unit_amount_minor' => $line->unitAmount->minor, 'amount_minor' => $amount->minor, 'currency' => $market->currency,
            'plan_price_version_id' => $line->planPriceVersionId, 'plan_version_id' => $line->planVersionId, 'period_start' => $start, 'period_end' => $end];
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
