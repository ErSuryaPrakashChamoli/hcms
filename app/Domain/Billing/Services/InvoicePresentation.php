<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoiceTaxLine;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Services\TaxEngine;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\MoneyFormatter;
use App\Support\Tenancy\TenantContext;

/**
 * SaaS.7: an invoice as a structured document (parties, lines, tax summary, totals) plus the rows its tax regime
 * adds (e.g. GSTINs and place of supply for India), with money formatted for the market's display locale. An
 * issued invoice is presented only from its frozen snapshot and rows, never from today's profiles, prices or
 * rules. Rendering is a set of small generic partials; a jurisdiction extends the data, not the template.
 */
final class InvoicePresentation
{
    public function __construct(private readonly TaxEngine $tax, private readonly TenantContext $tenants) {}

    /** @return array<string, mixed> read within the invoice's own tenant */
    public function document(Invoice $invoice): array
    {
        return $this->tenants->runAs(Tenant::query()->findOrFail($invoice->tenant_id), fn () => $this->build($invoice));
    }

    /** @return array<string, mixed> */
    private function build(Invoice $invoice): array
    {
        $snapshot = $invoice->snapshot ?? [];
        $locale = $snapshot['market']['locale'] ?? BillingMarket::query()->whereKey($invoice->market_id)->value('locale') ?? 'en';
        $format = fn (Money $m) => MoneyFormatter::format($m, $locale);
        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('line_no')->get();
        $taxLines = InvoiceTaxLine::query()->where('invoice_id', $invoice->id)->orderBy('line_no')->orderBy('id')->get();

        $summary = [];
        foreach ($taxLines as $line) {
            $key = "{$line->tax_type}|{$line->rate}";
            $summary[$key] ??= ['type' => $line->tax_type, 'rate' => rtrim(rtrim((string) $line->rate, '0'), '.').' %', 'taxable' => Money::zero($invoice->currency), 'tax' => Money::zero($invoice->currency)];
            $summary[$key]['taxable'] = $summary[$key]['taxable']->plus($line->taxable());
            $summary[$key]['tax'] = $summary[$key]['tax']->plus($line->tax());
        }
        $issued = $invoice->status !== InvoiceStatus::Draft && $invoice->status !== InvoiceStatus::Discarded;

        return [
            'status' => $invoice->status->value,
            'number' => $invoice->number,
            'reference' => $invoice->reference,
            'currency' => $invoice->currency->value,
            'locale' => $locale,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'overdue' => $invoice->isOverdue(),
            'period' => $invoice->period_start ? "{$invoice->period_start->toDateString()} – {$invoice->period_end->toDateString()}" : null,
            'supplier' => $issued ? $this->party($snapshot['supplier'] ?? []) : null,
            'customer' => $issued ? $this->party($snapshot['customer'] ?? []) : null,
            'lines' => $lines->map(fn (InvoiceLine $l) => ['no' => $l->line_no, 'description' => $l->description, 'quantity' => $l->quantity,
                'unit' => $format($l->unitAmount()), 'amount' => $format($l->amount()),
                'period' => $l->period_start ? "{$l->period_start->toDateString()} – {$l->period_end->toDateString()}" : null])->all(),
            'tax_summary' => array_values(array_map(fn (array $s) => ['type' => $s['type'], 'rate' => $s['rate'], 'taxable' => $format($s['taxable']), 'tax' => $format($s['tax'])], $summary)),
            'treatment' => $invoice->tax_treatment === null ? null : (TaxTreatment::tryFrom($invoice->tax_treatment)?->label() ?? $invoice->tax_treatment),
            'regime' => $snapshot['tax']['determination']['regime'] ?? null,
            'regime_rows' => $issued ? $this->tax->presentationRows($snapshot['tax'] ?? []) : [],
            'rule' => $snapshot['tax']['rule']['label'] ?? null,
            // SaaS.7 configuration: each tax leg (the supplier's and, for a cross-border supply, the customer's
            // country), the statutory wording the rules require, and the local-currency reporting value, all frozen.
            'legs' => $issued ? array_map(fn (array $leg) => ['role' => $leg['role'] === 'destination' ? "customer's country" : 'supplier', 'regime' => $leg['determination']['regime'] ?? null,
                'treatment' => TaxTreatment::tryFrom((string) ($leg['treatment'] ?? ''))?->label() ?? ($leg['treatment'] ?? null), 'rule' => $leg['rule']['rule_code'] ?? $leg['rule']['label'] ?? null],
                $snapshot['tax']['legs'] ?? []) : [],
            'wording' => $issued ? array_values(array_filter((array) ($snapshot['tax']['wording'] ?? []))) : [],
            'reporting' => $issued && isset($snapshot['reporting']['currency']) ? $this->reporting($snapshot['reporting'], $locale) : null,
            'totals' => ['subtotal' => $format($invoice->subtotal()), 'tax' => $issued ? $format($invoice->tax()) : null, 'total' => $issued ? $format($invoice->total()) : null],
        ];
    }

    /** @param  array<string, mixed>  $reporting  @return array{currency: string, rate: string, source: string, date: string, subtotal: string, tax: string, total: string} */
    private function reporting(array $reporting, string $locale): array
    {
        $currency = Currency::of((string) $reporting['currency']);
        $format = fn (string $key) => MoneyFormatter::format(Money::ofMinor((int) $reporting[$key], $currency), $locale);

        return ['currency' => $currency->value, 'rate' => (string) $reporting['rate'], 'source' => (string) $reporting['source'], 'date' => (string) $reporting['date'],
            'subtotal' => $format('subtotal_minor'), 'tax' => $format('tax_minor'), 'total' => $format('total_minor')];
    }

    /** @param  array<string, mixed>  $party  @return array{name: string, lines: list<string>} */
    private function party(array $party): array
    {
        return ['name' => (string) ($party['legal_name'] ?? '—'), 'lines' => array_values(array_filter(array_merge($party['address'] ?? [],
            [trim(($party['subdivision'] ?? '').' '.($party['country'] ?? ''))], [isset($party['billing_email']) ? (string) $party['billing_email'] : null])))];
    }
}
