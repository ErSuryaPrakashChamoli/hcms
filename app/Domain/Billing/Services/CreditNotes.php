<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoiceTaxLine;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Tax\Enums\TaxRounding;
use App\Domain\Tax\Services\TaxCalculator;
use App\Domain\Tax\Support\TaxComponent;
use App\Support\Commercial\OperatorChange;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * SaaS.7 completion (B-12, B-13): credit notes, the only way to reduce an issued invoice. A credit note is full (the
 * whole invoice: its cancellation) or partial (taxable amounts of chosen lines). Its tax mirrors the invoice: the
 * same components at the original rates and rounding (never today's rule); the last credit of a line takes exactly
 * what is left, so credit notes never exceed the invoice. One operator requests it, another approves; only the
 * approved execution numbers it (its own gap-free series) and, when the invoice is fully credited, closes the
 * invoice as credited. Debit notes are deferred (B-12): underbilling is a new invoice.
 */
final class CreditNotes
{
    public function __construct(private readonly BillingAudit $audit, private readonly FinancialApprovals $approvals, private readonly InvoiceSeries $series,
        private readonly Invoices $invoices, private readonly TenantContext $tenants) {}

    /** @param  array<int, string>|null  $lineAmounts  line number => taxable amount to credit (major units); null = everything still credited-able (full) */
    public function request(Invoice $invoice, ?array $lineAmounts, string $reason, User $maker): FinancialApproval
    {
        OperatorChange::assert($maker, $reason, 'credit notes');
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);

        return $this->tenants->runAs($tenant, function () use ($tenant, $invoice, $lineAmounts, $reason, $maker) {
            $invoice = Invoice::query()->findOrFail($invoice->id);
            $plan = $this->plan($invoice, $lineAmounts);
            $due = $this->invoices->amountDue($invoice);
            $credited = (int) CreditNote::query()->where('invoice_id', $invoice->id)->sum('total_minor');
            $total = Money::ofMinor($plan['total'], $invoice->currency);
            $cancels = $credited + $plan['total'] === $invoice->total_minor;

            return $this->approvals->request(ApprovalAction::CreditNote, $invoice, $tenant, [
                'invoice_reference' => $invoice->reference, 'invoice_number' => $invoice->number, 'tenant' => $tenant->name,
                'kind' => $credited === 0 && $cancels ? CreditNote::FULL : CreditNote::PARTIAL, 'cancels_invoice' => $cancels,
                'lines' => array_map(fn (array $l) => ['line_no' => $l['line_no'], 'taxable_minor' => $l['taxable_minor']], $plan['lines']),
                'total' => "{$invoice->currency->value} {$total->toDecimal()}",
            ], ['invoice_status' => $invoice->status->value, 'credited' => "{$invoice->currency->value} ".Money::ofMinor($credited, $invoice->currency)->toDecimal(),
                'amount_due' => "{$due->currency->value} {$due->toDecimal()}"],
                ['invoice_status' => $cancels ? 'credited' : $invoice->status->value, 'credited' => "{$invoice->currency->value} ".Money::ofMinor($credited + $plan['total'], $invoice->currency)->toDecimal()],
                $invoice->reference.':'.CreditNote::query()->where('invoice_id', $invoice->id)->count().':'.hash('crc32b', json_encode($plan['lines'])), $reason, $maker);
        });
    }

    /** Issues an approved credit note (called by the approval desk, inside its transaction). */
    public function execute(FinancialApproval $approval): CreditNote
    {
        $approval = $this->approvals->claim($approval, ApprovalAction::CreditNote);
        $tenant = Tenant::query()->findOrFail($approval->subject_tenant_id);

        return $this->tenants->runAs($tenant, function () use ($tenant, $approval) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($approval->subject_id);
            $amounts = collect($approval->payload['lines'])->mapWithKeys(fn (array $l) => [(int) $l['line_no'] => Money::ofMinor((int) $l['taxable_minor'], $invoice->currency)->toDecimal()])->all();
            $plan = $this->plan($invoice, $approval->payload['kind'] === CreditNote::FULL ? null : $amounts);
            if (array_map(fn (array $l) => $l['taxable_minor'], $plan['lines']) !== array_map(fn (array $l) => (int) $l['taxable_minor'], $approval->payload['lines'])) {
                throw new RuntimeException('The invoice was credited since this request: request the credit note again.');
            }
            $credited = (int) CreditNote::query()->where('invoice_id', $invoice->id)->sharedLock()->sum('total_minor');
            $today = now()->toDateString();
            [$series, $sequence, $number] = $this->series->allocate($invoice->supplier_entity, $today, 'credit_note');
            $checker = User::query()->findOrFail($approval->checker_id);
            $kind = $credited === 0 && $plan['total'] === $invoice->total_minor ? CreditNote::FULL : CreditNote::PARTIAL;
            $note = CreditNote::query()->create(['invoice_id' => $invoice->id, 'kind' => $kind, 'supplier_entity' => $invoice->supplier_entity, 'series_id' => $series->id,
                'sequence' => $sequence, 'number' => $number, 'issue_date' => $today, 'currency' => $invoice->currency, 'subtotal_minor' => $plan['subtotal'],
                'tax_minor' => $plan['tax'], 'total_minor' => $plan['total'], 'reason' => (string) $approval->maker_reason, 'approval_id' => $approval->id,
                'created_by' => $approval->maker_id, 'approved_by' => $checker->id, 'snapshot' => [
                    'invoice' => ['reference' => $invoice->reference, 'number' => $invoice->number, 'issue_date' => $invoice->issue_date->toDateString(),
                        'total_minor' => $invoice->total_minor],
                    'supplier' => $invoice->snapshot['supplier'] ?? null, 'customer' => $invoice->snapshot['customer'] ?? null, 'market' => $invoice->snapshot['market'] ?? null,
                    'tax' => ['regime' => $invoice->tax_regime, 'treatment' => $invoice->tax_treatment, 'rule' => $invoice->snapshot['tax']['rule'] ?? null],
                    'lines' => $plan['lines'],
                ]]);
            $total = $note->total();
            $this->audit->both(AuditAction::CreditNoteIssued, 'billing', $tenant, $note, "credit note {$number}",
                [['field' => 'credit_note', 'before' => 'none', 'after' => "{$number} {$total->currency->value} {$total->toDecimal()} ({$kind}) against {$invoice->number}"]],
                (string) $approval->checker_reason, $checker, ['credit_note_reference' => $note->reference, 'invoice_reference' => $invoice->reference, 'number' => $number,
                    'series_id' => $series->id, 'approval' => $approval->reference, 'maker_id' => $approval->maker_id, 'checker_id' => $checker->id,
                    'correlation_id' => $approval->correlation_key], $today);
            if ($credited + $plan['total'] === $invoice->total_minor) {
                $before = $invoice->status->value;
                $invoice->forceFill(['status' => InvoiceStatus::Credited, 'closed_at' => now(), 'closure_approval_id' => $approval->id])->save();
                $this->audit->both(AuditAction::InvoiceCredited, 'billing', $tenant, $invoice, "invoice {$invoice->number}",
                    [['field' => 'status', 'before' => $before, 'after' => 'credited']], (string) $approval->checker_reason, $checker,
                    ['invoice_reference' => $invoice->reference, 'credit_note' => $number, 'approval' => $approval->reference, 'correlation_id' => $approval->correlation_key], $today);
            }
            $this->approvals->executed($approval, "Credit note {$number}");

            return $note;
        });
    }

    /** @return Collection<int, CreditNote> */
    public function forInvoice(Invoice $invoice): Collection
    {
        return $this->tenants->runAs(Tenant::query()->findOrFail($invoice->tenant_id), fn () => CreditNote::query()->where('invoice_id', $invoice->id)->orderBy('id')->get());
    }

    /**
     * What a credit note would contain: per line the taxable amount and each tax component at the invoice's rate.
     *
     * @param  array<int, string>|null  $lineAmounts
     * @return array{lines: list<array{line_no: int, description: string, taxable_minor: int, taxes: list<array{type: string, rate: string, tax_minor: int}>}>, subtotal: int, tax: int, total: int}
     */
    private function plan(Invoice $invoice, ?array $lineAmounts): array
    {
        if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)) {
            throw new RuntimeException("A credit note is issued against an issued invoice that is not credited or written off; {$invoice->label()} is {$invoice->status->value}.");
        }
        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('line_no')->get()->keyBy('line_no');
        $taxes = InvoiceTaxLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->get()->groupBy('line_no');
        [$creditedTaxable, $creditedTax] = $this->alreadyCredited($invoice);
        $rounding = TaxRounding::tryFrom((string) ($invoice->snapshot['tax']['rule']['rounding_mode'] ?? '')) ?? TaxRounding::HalfUp;
        if ($lineAmounts !== null && $lineAmounts === []) {
            throw new RuntimeException('Choose at least one line and the amount to credit.');
        }
        $wanted = $lineAmounts ?? $lines->mapWithKeys(fn (InvoiceLine $l) => [$l->line_no => null])->all();
        $plan = ['lines' => [], 'subtotal' => 0, 'tax' => 0, 'total' => 0];
        foreach ($wanted as $lineNo => $amount) {
            $line = $lines->get((int) $lineNo) ?? throw new RuntimeException("Invoice {$invoice->label()} has no line {$lineNo}.");
            $remaining = $line->amount_minor - ($creditedTaxable[$line->line_no] ?? 0);
            if ($amount === null) {
                $taxable = $remaining;
            } else {
                try {
                    $taxable = Money::parse((string) $amount, $invoice->currency)->minor;
                } catch (InvalidArgumentException $e) {
                    throw new RuntimeException($e->getMessage());
                }
                if ($taxable <= 0 || $taxable > $remaining) {
                    throw new RuntimeException("Line {$line->line_no} can be credited by more than zero and at most ".Money::ofMinor($remaining, $invoice->currency)->toDecimal().'.');
                }
            }
            if ($taxable === 0) {
                continue;
            }
            $lineTaxes = [];
            $components = ($taxes->get($line->line_no) ?? collect())->map(fn (InvoiceTaxLine $t) => new TaxComponent($t->tax_type, (string) $t->rate))->values()->all();
            $calculated = TaxCalculator::calculate($invoice->currency, [$line->line_no => Money::ofMinor($taxable, $invoice->currency)], $components, $rounding);
            foreach (($taxes->get($line->line_no) ?? collect())->values() as $i => $original) {
                $left = $original->tax_minor - ($creditedTax[$line->line_no][$original->tax_type] ?? 0);
                // The last credit of a line takes exactly what is left; a partial one never more than that.
                $tax = $taxable === $remaining ? $left : min($calculated->lines[$i]->tax->minor, $left);
                $lineTaxes[] = ['type' => $original->tax_type, 'rate' => (string) $original->rate, 'tax_minor' => $tax];
                $plan['tax'] += $tax;
            }
            $plan['lines'][] = ['line_no' => $line->line_no, 'description' => $line->description, 'taxable_minor' => $taxable, 'taxes' => $lineTaxes];
            $plan['subtotal'] += $taxable;
        }
        if ($plan['lines'] === []) {
            throw new RuntimeException("Invoice {$invoice->label()} is already fully credited.");
        }
        $plan['total'] = $plan['subtotal'] + $plan['tax'];

        return $plan;
    }

    /** @return array{0: array<int, int>, 1: array<int, array<string, int>>} taxable and tax already credited per line (and tax type) */
    private function alreadyCredited(Invoice $invoice): array
    {
        $taxable = [];
        $tax = [];
        foreach (CreditNote::query()->where('invoice_id', $invoice->id)->sharedLock()->get() as $note) {   // latest committed, under the invoice lock
            foreach ($note->snapshot['lines'] ?? [] as $line) {
                $taxable[$line['line_no']] = ($taxable[$line['line_no']] ?? 0) + (int) $line['taxable_minor'];
                foreach ($line['taxes'] as $t) {
                    $tax[$line['line_no']][$t['type']] = ($tax[$line['line_no']][$t['type']] ?? 0) + (int) $t['tax_minor'];
                }
            }
        }

        return [$taxable, $tax];
    }
}
