<?php

namespace App\Filament\Pages;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoiceTdsClaim;
use App\Domain\Billing\Services\BillingDirectory;
use App\Domain\Billing\Services\CreditNotes;
use App\Domain\Billing\Services\InvoicePresentation;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\Payments;
use App\Domain\Payments\Services\ProviderRegistry;
use App\Domain\Payments\Services\Refunds;
use App\Domain\Payments\Services\TdsSettlement;
use App\Domain\Platform\Models\Tenant;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * SaaS.7: invoices for platform operators: every tenant's invoices (headers only) and one invoice as a document,
 * presented from its frozen snapshot once issued. Drafts show why they could not be issued today; issue fixes
 * the number, tax, totals and snapshots. Payments are started with a provider or recorded from a bank transfer;
 * confirmation only ever comes from a verified provider event, a server-side fetch or that recording. No page
 * drafts invoices by hand: amounts come from the billing calculation.
 *
 * SaaS.7 completion: credit notes (full = cancellation, or partial), write-offs and refunds are requested here and
 * approved by another operator on the Approvals page (B-13); customer TDS is declared and certified here (B-11);
 * a recorded transfer may carry what the bank credited Markedge (settlement, B-14).
 */
class PlatformInvoicesPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?string $title = 'Invoices';

    protected static ?string $slug = 'platform-invoices';

    protected static ?int $navigationSort = 26;

    protected string $view = 'filament.pages.platform-invoices';

    #[Url]
    public ?string $invoice = null;

    #[Url]
    public ?string $status = null;

    private ?Invoice $selected = null;

    private bool $resolved = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return Collection<int, Invoice> */
    public function invoices(): Collection
    {
        return app(BillingDirectory::class)->invoices(InvoiceStatus::tryFrom((string) $this->status));
    }

    public function selected(): ?Invoice
    {
        if (! $this->resolved) {
            $this->selected = $this->invoice ? app(BillingDirectory::class)->invoiceByReference($this->invoice) : null;
            $this->resolved = true;
        }

        return $this->selected;
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return app(InvoicePresentation::class)->document($this->selected());
    }

    /** @return array{ready: bool, problems: list<string>} */
    public function readiness(): array
    {
        return app(Invoices::class)->readiness($this->selected());
    }

    public function tenantName(): string
    {
        return (string) Tenant::query()->whereKey($this->selected()->tenant_id)->value('name');
    }

    /** @return Collection<int, Payment> */
    public function invoicePayments(): Collection
    {
        $invoice = $this->selected();

        return app(TenantContext::class)->runAs(Tenant::query()->findOrFail($invoice->tenant_id), fn () => Payment::query()->where('invoice_id', $invoice->id)->orderByDesc('id')->get());
    }

    public function amountDue(): Money
    {
        return app(Invoices::class)->amountDue($this->selected());
    }

    /** @return Collection<int, CreditNote> */
    public function creditNotes(): Collection
    {
        return app(CreditNotes::class)->forInvoice($this->selected());
    }

    public function tdsClaim(): ?InvoiceTdsClaim
    {
        $invoice = $this->selected();

        return app(TenantContext::class)->runAs(Tenant::query()->findOrFail($invoice->tenant_id), fn () => InvoiceTdsClaim::query()->where('invoice_id', $invoice->id)->first());
    }

    /** @return Collection<int, Refund> */
    public function refunds(): Collection
    {
        $invoice = $this->selected();

        return app(TenantContext::class)->runAs(Tenant::query()->findOrFail($invoice->tenant_id), fn () => Refund::query()
            ->whereIn('credit_note_id', CreditNote::query()->where('invoice_id', $invoice->id)->select('id'))->orderBy('id')->get());
    }

    /** @return Collection<int, InvoiceLine> */
    public function lines(): Collection
    {
        $invoice = $this->selected();

        return app(TenantContext::class)->runAs(Tenant::query()->findOrFail($invoice->tenant_id), fn () => InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('line_no')->get());
    }

    protected function getHeaderActions(): array
    {
        $reason = fn () => Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500);
        $is = fn (InvoiceStatus ...$statuses) => fn () => ($i = $this->selected()) !== null && in_array($i->status, $statuses, true);
        $starters = fn () => collect(app(ProviderRegistry::class)->all())->filter(fn ($p) => $p->startsPayments())->mapWithKeys(fn ($p, $key) => [$key => $p->label()])->all();

        return [
            Action::make('issue')->label('Issue invoice')->icon(Heroicon::OutlinedCheckBadge)->color('success')->visible($is(InvoiceStatus::Draft))
                ->modalDescription('Fixes the number, tax, totals and the supplier, customer and tax snapshots, dated today. Nothing financial changes afterwards.')
                ->schema([DatePicker::make('due')->label('Due date (optional; the configured payment terms when empty)')->native(false),
                    TextInput::make('reporting_rate')->label('Reporting rate (foreign-currency invoices where the law requires the value in local currency)')
                        ->placeholder('rate with up to 8 decimals')->helperText('Units of the local currency per unit of the invoice currency on the date of supply, as used in Markedge\'s accounts. Recorded on the invoice; prices are never converted.'),
                    TextInput::make('reporting_source')->label('Source of the rate')->maxLength(150)->placeholder('where the rate comes from (as recorded in the accounts)'),
                    DatePicker::make('reporting_date')->label('Rate date')->native(false),
                    $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(Invoices::class)->issue($this->selected(), blank($data['due'] ?? null) ? null : substr((string) $data['due'], 0, 10), $data['reason'], auth()->user(),
                    blank($data['reporting_rate'] ?? null) ? null : ['rate' => trim((string) $data['reporting_rate']), 'source' => (string) ($data['reporting_source'] ?? ''),
                        'date' => blank($data['reporting_date'] ?? null) ? null : substr((string) $data['reporting_date'], 0, 10)]), 'Invoice issued')),
            Action::make('discard')->label('Discard draft')->icon(Heroicon::OutlinedTrash)->color('danger')->visible($is(InvoiceStatus::Draft))
                ->schema([$reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(Invoices::class)->discard($this->selected(), $data['reason'], auth()->user()), 'Draft discarded')),
            Action::make('startPayment')->label('Start payment')->icon(Heroicon::OutlinedCreditCard)->visible(fn () => $is(InvoiceStatus::Issued)() && $starters() !== [])
                ->modalDescription('Asks the provider to collect the exact amount due. The payment is confirmed only by the provider\'s verified notification or a server-side check.')
                ->schema([Select::make('provider')->label('Provider')->required()->options($starters), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(Payments::class)->initiate($this->selected(), $data['provider'], $data['reason'], auth()->user()), 'Payment started')),
            Action::make('recordTransfer')->label('Record bank transfer')->icon(Heroicon::OutlinedBuildingLibrary)->visible($is(InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid))
                ->modalDescription('Records money received outside PeopleOS. It settles the invoice only if it is exactly the amount due in the invoice\'s currency; anything else becomes a reconciliation exception. A short payment is never assumed to be TDS: declare TDS first.')
                ->schema([
                    TextInput::make('amount')->label('Amount received (major units)')->required(),
                    Select::make('currency')->label('Currency received')->required()->options(Currency::options())->default(fn () => $this->selected()?->currency->value),
                    TextInput::make('bank_reference')->label('Bank reference (UTR, SWIFT, SEPA)')->required()->maxLength(64),
                    DatePicker::make('received_on')->label('Received on')->native(false)->required()->default(now()->toDateString()),
                    TextInput::make('settlement_amount')->label('Credited to Markedge (optional, e.g. INR after conversion)'),
                    Select::make('settlement_currency')->label('Credited currency')->options(Currency::options()),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(Payments::class)->recordBankTransfer($this->selected(), (string) $data['amount'], (string) $data['currency'],
                    (string) $data['bank_reference'], substr((string) $data['received_on'], 0, 10), $data['reason'], auth()->user(),
                    blank($data['settlement_amount'] ?? null) ? null : (string) $data['settlement_amount'], $data['settlement_currency'] ?? null), 'Transfer recorded')),
            Action::make('declareTds')->label('Declare customer TDS')->icon(Heroicon::OutlinedReceiptPercent)->visible(fn () => $is(InvoiceStatus::Issued)() && $this->tdsClaim() === null)
                ->modalDescription('The income-tax TDS the customer deducted, as shown on its statement (no rate is assumed). The amount due becomes the total less it; the invoice is paid once the remainder is received and the certificate recorded.')
                ->schema([
                    TextInput::make('amount')->label('TDS deducted (major units)')->required(),
                    TextInput::make('certificate')->label('Certificate or statement reference (optional now)')->maxLength(100),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(TdsSettlement::class)->declare($this->selected(), (string) $data['amount'], $data['certificate'] ?? null, $data['reason'], auth()->user()), 'TDS declared')),
            Action::make('certifyTds')->label('Record TDS certificate')->icon(Heroicon::OutlinedDocumentCheck)->visible(fn () => $this->selected()?->status?->isIssuedDocument() && $this->tdsClaim()?->status === InvoiceTdsClaim::PENDING)
                ->schema([TextInput::make('certificate')->label('Certificate reference')->required()->maxLength(100), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(TdsSettlement::class)->certify($this->tdsClaim(), (string) $data['certificate'], $data['reason'], auth()->user()), 'TDS certificate recorded')),
            Action::make('requestCreditNote')->label('Request credit note')->icon(Heroicon::OutlinedReceiptRefund)->color('warning')
                ->visible($is(InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid))
                ->modalDescription('Maker-checker: another operator approves it on the Approvals page. A full credit note cancels the invoice; a partial one credits an amount of one line. Tax mirrors the invoice at its original rates.')
                ->schema([
                    Select::make('kind')->label('Credit')->required()->live()->options(['full' => 'Everything still uncredited (cancels the invoice)', 'partial' => 'Part of one line'])->default('full'),
                    Select::make('line')->label('Line')->options(fn () => $this->lines()->mapWithKeys(fn (InvoiceLine $l) => [$l->line_no => "{$l->line_no}. ".mb_substr($l->description, 0, 80)])->all())
                        ->visible(fn (Get $get) => $get('kind') === 'partial')->required(fn (Get $get) => $get('kind') === 'partial'),
                    TextInput::make('amount')->label('Taxable amount to credit (major units)')->visible(fn (Get $get) => $get('kind') === 'partial')->required(fn (Get $get) => $get('kind') === 'partial'),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(CreditNotes::class)->request($this->selected(), $data['kind'] === 'full' ? null : [(int) $data['line'] => (string) $data['amount']],
                    $data['reason'], auth()->user()), 'Credit note requested: another operator approves it')),
            Action::make('requestWriteOff')->label('Request write-off')->icon(Heroicon::OutlinedNoSymbol)->color('danger')->visible($is(InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid))
                ->modalDescription('Maker-checker: another operator approves it. The unpaid invoice is closed as written off; the tax document itself is unchanged.')
                ->schema([$reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(Invoices::class)->requestWriteOff($this->selected(), $data['reason'], auth()->user()), 'Write-off requested: another operator approves it')),
            Action::make('requestRefund')->label('Request refund')->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
                ->visible(fn () => $this->selected()?->status?->isIssuedDocument() && $this->creditNotes()->isNotEmpty())
                ->modalDescription('Maker-checker. A refund returns money only against an issued credit note, from a succeeded payment of this invoice; through the provider, or for a bank transfer by a transfer recorded afterwards.')
                ->schema([
                    Select::make('credit_note')->label('Credit note')->required()->options(fn () => $this->creditNotes()->mapWithKeys(fn (CreditNote $n) => [$n->id => "{$n->number} · {$n->currency->value} {$n->total()->toDecimal()}"])->all()),
                    Select::make('payment')->label('Payment')->required()->options(fn () => $this->invoicePayments()->where('status', PaymentStatus::Succeeded)->mapWithKeys(fn (Payment $p) => [$p->id => "{$p->provider} · {$p->currency->value} {$p->amount()->toDecimal()}"])->all()),
                    TextInput::make('amount')->label('Amount to refund (major units)')->required(),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(Refunds::class)->request($this->creditNotes()->firstWhere('id', (int) $data['credit_note']),
                    $this->invoicePayments()->firstWhere('id', (int) $data['payment']), (string) $data['amount'], $data['reason'], auth()->user()), 'Refund requested: another operator approves it')),
            Action::make('recordRefundTransfer')->label('Record refund transfer')->icon(Heroicon::OutlinedBuildingLibrary)->color('gray')
                ->visible(fn () => $this->selected()?->status?->isIssuedDocument() && $this->refunds()->contains(fn (Refund $r) => $r->status === Refund::PROCESSING && $r->provider === 'manual'))
                ->modalDescription('An approved refund of a bank transfer is paid outside PeopleOS: record the outgoing transfer\'s reference.')
                ->schema([
                    Select::make('refund')->label('Refund')->required()->options(fn () => $this->refunds()->filter(fn (Refund $r) => $r->status === Refund::PROCESSING && $r->provider === 'manual')
                        ->mapWithKeys(fn (Refund $r) => [$r->id => "{$r->currency->value} {$r->amount()->toDecimal()} ({$r->reference})"])->all()),
                    TextInput::make('bank_reference')->label('Bank reference of the refund')->required()->maxLength(64),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(Refunds::class)->recordManual($this->refunds()->firstWhere('id', (int) $data['refund']), (string) $data['bank_reference'],
                    $data['reason'], auth()->user()), 'Refund recorded')),
        ];
    }

    private function attempt(\Closure $work, string $done): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $work();
            Notification::make()->success()->title($done)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        } finally {
            $this->resolved = false;
        }
    }
}
