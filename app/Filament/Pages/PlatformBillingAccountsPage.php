<?php

namespace App\Filament\Pages;

use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\SubscriptionBillingTerm;
use App\Domain\Billing\Models\TenantBillingProfile;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Billing\Services\BillingDirectory;
use App\Domain\Billing\Services\BillingProfiles;
use App\Domain\Billing\Services\BillingTerms;
use App\Domain\Payments\Models\Payment;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Domain\Tax\Enums\CustomerType;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegistration;
use App\Domain\Tax\Services\TaxRegistry;
use App\Support\Money\Money;
use App\Support\Money\MoneyFormatter;
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
 * SaaS.7: tenants' billing accounts for platform operators: every tenant's billing profile in force and open
 * invoices, and for one tenant its billing profile versions (market, B2B/B2C, jurisdiction, tax registration),
 * the billing terms of its subscriptions (which price applies on which day) and its invoices and payments. A
 * tenant without a profile cannot be invoiced; nothing is created for any tenant by default.
 */
class PlatformBillingAccountsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Billing accounts';

    protected static ?string $title = 'Billing accounts';

    protected static ?string $slug = 'platform-billing-accounts';

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.platform-billing-accounts';

    #[Url]
    public ?int $tenant = null;

    /** @var array<int, Tenant|null> */
    private array $tenants = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function today(): string
    {
        return now()->toDateString();
    }

    public function selectedTenant(): ?Tenant
    {
        return $this->tenant ? ($this->tenants[$this->tenant] ??= Tenant::query()->find($this->tenant)) : null;
    }

    public function overview(): Collection
    {
        return app(BillingDirectory::class)->accounts($this->today());
    }

    /** @return Collection<int, TenantBillingProfile> newest first */
    public function profiles(): Collection
    {
        return $this->inTenant(fn () => TenantBillingProfile::query()->with('market')->orderByDesc('version')->get());
    }

    /** @return Collection<int, array{subscription: TenantSubscription, terms: Collection<int, SubscriptionBillingTerm>, applies: ?array}> */
    public function terms(): Collection
    {
        return $this->inTenant(fn () => TenantSubscription::query()->orderByDesc('id')->get()->map(fn (TenantSubscription $s) => ['subscription' => $s,
            'terms' => SubscriptionBillingTerm::query()->with('priceVersion', 'market')->where('subscription_id', $s->id)->orderByDesc('id')->get(),
            'applies' => app(BillingTerms::class)->applicableOn($s, $this->today())]));
    }

    /** @return Collection<int, Invoice> */
    public function invoices(): Collection
    {
        return $this->inTenant(fn () => Invoice::query()->orderByDesc('id')->limit(50)->get());
    }

    /** @return Collection<int, Payment> */
    public function payments(): Collection
    {
        return $this->inTenant(fn () => Payment::query()->orderByDesc('id')->limit(50)->get());
    }

    public function format(Money $money, ?string $locale = null): string
    {
        return MoneyFormatter::format($money, $locale ?? 'en');
    }

    protected function getHeaderActions(): array
    {
        $reason = fn () => Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500);
        $registry = app(TaxRegistry::class);
        $hasTenant = fn () => $this->selectedTenant() !== null;

        return [
            Action::make('recordProfile')->label('Record billing profile')->icon(Heroicon::OutlinedIdentification)->visible(fn () => $hasTenant() && BillingMarket::query()->exists())
                ->modalDescription('A new version of the tenant\'s billing identity from a date. The billing jurisdiction is what you record here; it is never derived from the tenant\'s location, locale or currency.')
                ->schema([
                    Select::make('market')->label('Market')->required()->options(fn () => BillingMarket::query()->orderBy('code')->get()->mapWithKeys(fn ($m) => [$m->id => $m->label()])->all()),
                    Select::make('customer_type')->label('Customer type')->required()->options(collect(CustomerType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()),
                    TextInput::make('legal_name')->label('Legal or full name')->required()->maxLength(200),
                    TextInput::make('billing_email')->label('Billing e-mail')->email()->required()->maxLength(254),
                    TextInput::make('billing_contact')->label('Billing contact')->maxLength(120),
                    TextInput::make('address_line1')->label('Address')->required()->maxLength(200),
                    TextInput::make('address_line2')->label('Address (line 2)')->maxLength(200),
                    TextInput::make('city')->label('City')->required()->maxLength(100),
                    TextInput::make('postal_code')->label('Postal code')->maxLength(20),
                    TextInput::make('country')->label('Billing country (ISO code)')->required()->length(2)->live(onBlur: true),
                    Select::make('subdivision_known')->label('State or territory')->searchable()->options(fn (Get $get) => $registry->subdivisions(strtoupper((string) $get('country'))))
                        ->visible(fn (Get $get) => $registry->subdivisions(strtoupper((string) $get('country'))) !== []),
                    TextInput::make('subdivision_text')->label('State or province (ISO 3166-2, optional)')->maxLength(8)
                        ->visible(fn (Get $get) => $registry->subdivisions(strtoupper((string) $get('country'))) === []),
                    Select::make('tax_registration')->label('Tax registration')->required()->options(collect(TaxRegistration::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()),
                    Select::make('tax_id_type')->label('Tax identifier type')->options(collect(TaxIdType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()),
                    TextInput::make('tax_id_value')->label('Tax identifier')->maxLength(32),
                    Select::make('special_tax_status')->label('Special tax status')->options(fn (Get $get) => $registry->specialStatuses(strtoupper((string) $get('country'))))
                        ->visible(fn (Get $get) => $registry->specialStatuses(strtoupper((string) $get('country'))) !== []),
                    DatePicker::make('from')->label('From')->native(false)->required()->default(now()->toDateString()),
                    TextInput::make('reference')->label('Reference')->maxLength(100),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(BillingProfiles::class)->record($this->selectedTenant(), BillingMarket::query()->findOrFail((int) $data['market']),
                    $data + ['subdivision' => $data['subdivision_known'] ?? $data['subdivision_text'] ?? null], substr((string) $data['from'], 0, 10), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Billing profile recorded')),
            Action::make('setTerms')->label('Set billing terms')->icon(Heroicon::OutlinedTag)->visible(fn () => $hasTenant() && $this->inTenant(fn () => TenantSubscription::query()->exists()))
                ->modalDescription('Pins the price version a subscription is billed at from a date. A later price reaches it only through a new pin.')
                ->schema([
                    Select::make('subscription')->label('Subscription')->required()->live()->options(fn () => $this->inTenant(fn () => TenantSubscription::query()->orderByDesc('id')->pluck('id', 'id')->map(fn ($id) => "#{$id}")->all())),
                    DatePicker::make('from')->label('From')->native(false)->required()->live()->default(now()->toDateString()),
                    Select::make('version')->label('Price version on sale')->required()->options(fn (Get $get) => $this->priceOptions((int) $get('subscription'), substr((string) ($get('from') ?: $this->today()), 0, 10))),
                    TextInput::make('reference')->label('Contract or order reference')->maxLength(100),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(BillingTerms::class)->set($this->inTenant(fn () => TenantSubscription::query()->findOrFail((int) $data['subscription'])),
                    PlanPriceVersion::query()->findOrFail((int) $data['version']), substr((string) $data['from'], 0, 10), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Billing terms set')),
        ];
    }

    /** @return array<int, string> the price versions on sale on $day for the subscription's plan version in the tenant's market */
    private function priceOptions(int $subscriptionId, string $day): array
    {
        $tenant = $this->selectedTenant();
        $subscription = $tenant === null || $subscriptionId === 0 ? null : $this->inTenant(fn () => TenantSubscription::query()->with('periods')->find($subscriptionId));
        $state = $subscription?->timeline()->stateOn($day);
        $profile = $tenant === null ? null : app(BillingProfiles::class)->inForce($tenant, $day);
        if ($state === null || $profile === null) {
            return [];
        }
        $catalog = app(BillingCatalog::class);

        return PlanPrice::query()->with('planVersion.plan', 'market')->where(['plan_version_id' => $state['plan_version_id'], 'market_id' => $profile->market_id])->get()
            ->mapWithKeys(fn (PlanPrice $p) => ($v = $catalog->versionOnSale($p, $day)) === null ? [] : [$v->id => "{$catalog->priceLabel($p)} · {$v->currency->value} {$v->amount()->toDecimal()} (v{$v->version})"])->all();
    }

    private function inTenant(\Closure $work): mixed
    {
        $tenant = $this->selectedTenant();

        return $tenant === null ? collect() : app(TenantContext::class)->runAs($tenant, $work);
    }

    private function attempt(\Closure $work, string $done): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $work();
            Notification::make()->success()->title($done)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
