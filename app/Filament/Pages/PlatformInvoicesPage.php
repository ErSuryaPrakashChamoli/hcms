<?php

namespace App\Filament\Pages;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\BillingDirectory;
use App\Domain\Billing\Services\InvoicePresentation;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\Payments;
use App\Domain\Payments\Services\ProviderRegistry;
use App\Domain\Platform\Models\Tenant;
use App\Support\Money\Currency;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
 * drafts invoices by hand: amounts come from the billing calculation once decided.
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

    protected function getHeaderActions(): array
    {
        $reason = fn () => Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500);
        $is = fn (InvoiceStatus ...$statuses) => fn () => ($i = $this->selected()) !== null && in_array($i->status, $statuses, true);
        $starters = fn () => collect(app(ProviderRegistry::class)->all())->filter(fn ($p) => $p->startsPayments())->mapWithKeys(fn ($p, $key) => [$key => $p->label()])->all();

        return [
            Action::make('issue')->label('Issue invoice')->icon(Heroicon::OutlinedCheckBadge)->color('success')->visible($is(InvoiceStatus::Draft))
                ->modalDescription('Fixes the number, tax, totals and the supplier, customer and tax snapshots, dated today. Nothing financial changes afterwards.')
                ->schema([DatePicker::make('due')->label('Due date (optional)')->native(false), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(Invoices::class)->issue($this->selected(), blank($data['due'] ?? null) ? null : substr((string) $data['due'], 0, 10), $data['reason'], auth()->user()), 'Invoice issued')),
            Action::make('discard')->label('Discard draft')->icon(Heroicon::OutlinedTrash)->color('danger')->visible($is(InvoiceStatus::Draft))
                ->schema([$reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(Invoices::class)->discard($this->selected(), $data['reason'], auth()->user()), 'Draft discarded')),
            Action::make('startPayment')->label('Start payment')->icon(Heroicon::OutlinedCreditCard)->visible(fn () => $is(InvoiceStatus::Issued)() && $starters() !== [])
                ->modalDescription('Asks the provider to collect the exact total. The payment is confirmed only by the provider\'s verified notification or a server-side check.')
                ->schema([Select::make('provider')->label('Provider')->required()->options($starters), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(Payments::class)->initiate($this->selected(), $data['provider'], $data['reason'], auth()->user()), 'Payment started')),
            Action::make('recordTransfer')->label('Record bank transfer')->icon(Heroicon::OutlinedBuildingLibrary)->visible($is(InvoiceStatus::Issued, InvoiceStatus::Paid))
                ->modalDescription('Records money received outside PeopleOS. It settles the invoice only if it is exactly the total in the invoice\'s currency; anything else becomes a reconciliation exception.')
                ->schema([
                    TextInput::make('amount')->label('Amount received (major units)')->required(),
                    Select::make('currency')->label('Currency received')->required()->options(Currency::options())->default(fn () => $this->selected()?->currency->value),
                    TextInput::make('bank_reference')->label('Bank reference (UTR, SWIFT, SEPA)')->required()->maxLength(64),
                    DatePicker::make('received_on')->label('Received on')->native(false)->required()->default(now()->toDateString()),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(Payments::class)->recordBankTransfer($this->selected(), (string) $data['amount'], (string) $data['currency'],
                    (string) $data['bank_reference'], substr((string) $data['received_on'], 0, 10), $data['reason'], auth()->user()), 'Transfer recorded')),
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
