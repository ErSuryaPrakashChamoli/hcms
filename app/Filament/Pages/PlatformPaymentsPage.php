<?php

namespace App\Filament\Pages;

use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Services\PaymentDirectory;
use App\Domain\Payments\Services\Payments;
use App\Domain\Platform\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * SaaS.7: payments for platform operators: every payment (headers only), reconciliation exceptions to resolve, and
 * the verified provider events received (status and outcome only; payloads are never shown). An operator can ask
 * the provider, server-side, what happened to a payment, or resolve an exception with a note; neither settles an
 * invoice by itself: only an exact, verified amount does.
 */
class PlatformPaymentsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $title = 'Payments and reconciliation';

    protected static ?string $slug = 'platform-payments';

    protected static ?int $navigationSort = 27;

    protected string $view = 'filament.pages.platform-payments';

    #[Url]
    public ?string $payment = null;

    #[Url]
    public bool $exceptions = false;

    private ?Payment $selected = null;

    private bool $resolved = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return Collection<int, Payment> */
    public function payments(): Collection
    {
        return app(PaymentDirectory::class)->payments($this->exceptions);
    }

    /** @return Collection<int, PaymentProviderEvent> */
    public function events(): Collection
    {
        return app(PaymentDirectory::class)->events();
    }

    public function selected(): ?Payment
    {
        if (! $this->resolved) {
            $this->selected = $this->payment ? app(PaymentDirectory::class)->paymentByReference($this->payment) : null;
            $this->resolved = true;
        }

        return $this->selected;
    }

    public function tenantName(): string
    {
        return (string) Tenant::query()->whereKey($this->selected()->tenant_id)->value('name');
    }

    public function invoiceReference(): ?string
    {
        return app(PaymentDirectory::class)->invoiceReference($this->selected());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label('Check with provider')->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn () => ($p = $this->selected()) !== null && $p->provider !== 'manual' && $p->provider_reference !== null && ! $p->status->isFinal())
                ->modalDescription('Asks the provider, server-side, for the payment\'s state and applies it like any verified notification.')
                ->schema([Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)])
                ->action(fn (array $data) => $this->attempt(fn () => app(Payments::class)->refresh($this->selected(), $data['reason'], auth()->user()), 'Checked with provider')),
            Action::make('resolveException')->label('Resolve exception')->icon(Heroicon::OutlinedCheckCircle)->color('warning')
                ->visible(fn () => $this->selected()?->reconciliation_status === ReconciliationStatus::Exception)
                ->modalDescription('Records how the exception was handled outside PeopleOS (e.g. refunded by bank). The invoice is not changed; there are no credit notes or refunds yet.')
                ->schema([Textarea::make('note')->label('How it was handled')->required()->minLength(5)->maxLength(500)])
                ->action(fn (array $data) => $this->attempt(fn () => app(Payments::class)->resolveException($this->selected(), $data['note'], auth()->user()), 'Exception resolved')),
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
