<?php

namespace App\Filament\Pages;

use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Enums\ApprovalStatus;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PricingBasis;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\BillingPeriod;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\NegotiatedPrice;
use App\Domain\Billing\Models\NegotiatedPriceVersion;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\PriceChangeNotice;
use App\Domain\Billing\Models\SubscriptionBillingTerm;
use App\Domain\Billing\Models\TenantBillingProfile;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Billing\Services\BillingDirectory;
use App\Domain\Billing\Services\BillingPeriods;
use App\Domain\Billing\Services\BillingProfiles;
use App\Domain\Billing\Services\BillingTerms;
use App\Domain\Billing\Services\NegotiatedPrices;
use App\Domain\Billing\Services\PriceNotices;
use App\Domain\Configuration\Enums\VersionStatus;
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
 *
 * SaaS.7 completion: annual terms with a committed quantity, price-increase notices and the re-pin worklist
 * (B-15), and the calculated billing periods with their frozen quantity evidence; an operator may run the billing
 * calculation for the tenant (drafts only) and redraft a period whose draft was discarded.
 *
 * SaaS.7 configuration: the tenant's negotiated prices (customer deals: PEPM or fixed, minimum, discount, contract
 * window and reference), drafted and published by maker-checker, and pinned as billing terms; agreed terms take
 * precedence over the standard price. Each subscription shows where today's price comes from, or NO PRICE
 * CONFIGURED.
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

    /** Per-request memo of the selected tenant's deals, the pending deal publications and plan labels (reset after an action). */
    private ?Collection $deals = null;

    private ?array $pendingDeals = null;

    /** @var array<int, string> */
    private array $planLabels = [];

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
            'terms' => SubscriptionBillingTerm::query()->with('priceVersion', 'negotiatedVersion', 'market')->where('subscription_id', $s->id)->orderByDesc('id')->get(),
            'applies' => app(BillingTerms::class)->applicableOn($s, $this->today())]));
    }

    /** @return Collection<int, NegotiatedPrice> the tenant's deals with their versions */
    public function deals(): Collection
    {
        $tenant = $this->selectedTenant();

        return $this->deals ??= ($tenant === null ? collect() : app(NegotiatedPrices::class)->forTenant($tenant));
    }

    public function dealState(NegotiatedPriceVersion $version): string
    {
        $this->pendingDeals ??= FinancialApproval::query()->where(['action' => ApprovalAction::NegotiatedPricePublication, 'status' => ApprovalStatus::Pending])
            ->where('subject_tenant_id', $this->tenant)->pluck('subject_id')->all();

        return app(NegotiatedPrices::class)->state($version, $this->today(), in_array($version->id, $this->pendingDeals, false));
    }

    public function describeDeal(NegotiatedPriceVersion $version): string
    {
        return $this->inTenant(fn () => app(NegotiatedPrices::class)->describe($version));
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

    /** @return Collection<int, BillingPeriod> */
    public function periods(): Collection
    {
        $tenant = $this->selectedTenant();

        return $tenant === null ? collect() : app(BillingPeriods::class)->periods($tenant, 60);
    }

    /** @return Collection<int, PriceChangeNotice> */
    public function notices(): Collection
    {
        return $this->inTenant(fn () => PriceChangeNotice::query()->with('toVersion', 'fromVersion')->orderByDesc('effective_from')->limit(50)->get());
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
                    TextInput::make('tax_locality')->label('Local tax jurisdiction (county, city or district code from Markedge\'s rate source)')->maxLength(40)
                        ->helperText('Needed where the state applies local rates; recorded, never derived from the address.')
                        ->visible(fn (Get $get) => $registry->usesLocalities((string) $get('country'))),
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
            Action::make('createDeal')->label('New negotiated price')->icon(Heroicon::OutlinedHandRaised)->visible(fn () => $hasTenant() && $this->inTenant(fn () => TenantSubscription::query()->exists()))
                ->modalDescription('A customer-specific deal for one subscription: plan version, market, interval, PEPM or fixed, and the contract window. Its amounts are versions published by maker-checker. It never changes the standard catalogue or any other customer.')
                ->schema([
                    Select::make('subscription')->label('Subscription')->required()->options(fn () => $this->inTenant(fn () => TenantSubscription::query()->orderByDesc('id')->pluck('id', 'id')->map(fn ($id) => "#{$id}")->all())),
                    Select::make('plan_version')->label('Published plan version')->required()->options(fn () => app(BillingCatalog::class)->publishedPlanVersions()),
                    Select::make('market')->label('Market (the deal is in its currency, never converted)')->required()->options(fn () => BillingMarket::query()->orderBy('code')->get()->mapWithKeys(fn ($m) => [$m->id => $m->label()])->all()),
                    Select::make('interval')->label('Interval')->required()->options(collect(BillingInterval::cases())->mapWithKeys(fn ($i) => [$i->value => $i->label()])->all()),
                    Select::make('basis')->label('Basis')->required()->options(collect(PricingBasis::cases())->mapWithKeys(fn ($b) => [$b->value => $b === PricingBasis::Flat ? 'fixed amount per billing interval (a month, or a year for annual terms)' : $b->label()])->all()),
                    DatePicker::make('contract_start')->label('Contract starts')->native(false)->required(),
                    DatePicker::make('contract_end')->label('Contract ends (optional)')->native(false),
                    TextInput::make('contract_reference')->label('Contract reference')->maxLength(100),
                    Textarea::make('notes')->label('Notes')->maxLength(2000),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(NegotiatedPrices::class)->create($this->inTenant(fn () => TenantSubscription::query()->findOrFail((int) $data['subscription'])),
                    (int) $data['plan_version'], BillingMarket::query()->findOrFail((int) $data['market']), $data['interval'], $data['basis'], substr((string) $data['contract_start'], 0, 10),
                    blank($data['contract_end'] ?? null) ? null : substr((string) $data['contract_end'], 0, 10), $data['contract_reference'] ?? null, $data['notes'] ?? null, $data['reason'],
                    auth()->user()), 'Negotiated price recorded: draft its amount')),
            Action::make('draftDealVersion')->label('Draft deal amount')->icon(Heroicon::OutlinedDocumentPlus)->visible(fn () => $hasTenant() && $this->deals()->isNotEmpty())
                ->schema([
                    Select::make('deal')->label('Negotiated price')->required()->options(fn () => $this->deals()->mapWithKeys(fn (NegotiatedPrice $d) => [$d->id => $this->dealLabel($d)])->all()),
                    TextInput::make('amount')->label('Agreed unit amount in the market currency (major units)')->required()->placeholder('amount in major units, e.g. 0000.00')
                        ->helperText('Per employee per month for PEPM; for a fixed price, the amount per billing interval (per year for an annual deal). Exact to the currency\'s decimals; never converted.'),
                    TextInput::make('minimum')->label('Minimum employees (PEPM only, optional)')->numeric()->minValue(0)->default(0),
                    TextInput::make('discount')->label('Discount % on the unit (optional)')->placeholder('percentage, e.g. 0.00'),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(NegotiatedPrices::class)->draftVersion($this->inTenant(fn () => NegotiatedPrice::query()->findOrFail((int) $data['deal'])),
                    (string) $data['amount'], (int) ($data['minimum'] ?? 0), $data['discount'] ?? null, null, $data['reason'], auth()->user()), 'Deal amount drafted')),
            Action::make('requestDealPublication')->label('Request deal publication')->icon(Heroicon::OutlinedRocketLaunch)->color('success')
                ->visible(fn () => $hasTenant() && $this->dealVersionsWhere(VersionStatus::Draft) !== [])
                ->modalDescription('Maker-checker: another operator approves on the Approvals page. Once published from the date it never changes; pin it as billing terms.')
                ->schema([
                    Select::make('version')->label('Draft')->required()->options(fn () => $this->dealVersionsWhere(VersionStatus::Draft)),
                    DatePicker::make('from')->label('Applies from')->native(false)->required()->default(now()->toDateString()),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(NegotiatedPrices::class)->requestPublication($this->inTenant(fn () => NegotiatedPriceVersion::query()->findOrFail((int) $data['version'])),
                    substr((string) $data['from'], 0, 10), $data['reason'], auth()->user()), 'Publication requested: another operator approves it')),
            Action::make('retireDealVersion')->label('Retire deal amount')->icon(Heroicon::OutlinedArchiveBox)->color('gray')
                ->visible(fn () => $hasTenant() && ($this->dealVersionsWhere(VersionStatus::Draft) + $this->dealVersionsWhere(VersionStatus::Published)) !== [])
                ->modalDescription('A published version stops applying to new pins (terms already pinned keep it); a draft is abandoned.')
                ->schema([Select::make('version')->label('Version')->required()->options(fn () => $this->dealVersionsWhere(VersionStatus::Draft) + $this->dealVersionsWhere(VersionStatus::Published)), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(NegotiatedPrices::class)->retireVersion($this->inTenant(fn () => NegotiatedPriceVersion::query()->findOrFail((int) $data['version'])),
                    $data['reason'], auth()->user()), 'Deal amount retired')),
            Action::make('setTerms')->label('Set billing terms')->icon(Heroicon::OutlinedTag)->visible(fn () => $hasTenant() && $this->inTenant(fn () => TenantSubscription::query()->exists()))
                ->modalDescription('Pins the price a subscription is billed at from a date: the customer\'s agreed price when one is in force (it takes precedence), else the standard version on sale. A later price reaches it only through a new pin.')
                ->schema([
                    Select::make('subscription')->label('Subscription')->required()->live()->options(fn () => $this->inTenant(fn () => TenantSubscription::query()->orderByDesc('id')->pluck('id', 'id')->map(fn ($id) => "#{$id}")->all())),
                    DatePicker::make('from')->label('From')->native(false)->required()->live()->default(now()->toDateString()),
                    Select::make('version')->label('Price')->required()->options(fn (Get $get) => $this->priceOptions((int) $get('subscription'), substr((string) ($get('from') ?: $this->today()), 0, 10), withAgreed: true)),
                    TextInput::make('committed')->label('Committed employees (annual terms only)')->numeric()->minValue(1)
                        ->helperText('Annual terms are billed in advance on this quantity (at least the price\'s minimum); months above it are billed as true-up.'),
                    TextInput::make('reference')->label('Contract or order reference')->maxLength(100),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(BillingTerms::class)->set($this->inTenant(fn () => TenantSubscription::query()->findOrFail((int) $data['subscription'])),
                    $this->pinnable((string) $data['version']), substr((string) $data['from'], 0, 10), $data['reason'], auth()->user(), $data['reference'] ?? null,
                    blank($data['committed'] ?? null) ? null : (int) $data['committed']), 'Billing terms set')),
            Action::make('recordNotice')->label('Record price notice')->icon(Heroicon::OutlinedEnvelope)->visible(fn () => $hasTenant() && $this->inTenant(fn () => SubscriptionBillingTerm::query()->exists()))
                ->modalDescription(fn () => 'A standard price increase reaches an existing subscriber only after a written notice of at least the configured period ('.PriceNotices::noticeDays($this->today()).' days today; Commercial policies), at the next period (monthly) or renewal (annual). Recording the notice changes no price: re-pin the terms when it falls due. A negotiated price changes by its contract instead.')
                ->schema([
                    Select::make('subscription')->label('Subscription')->required()->live()->options(fn () => $this->inTenant(fn () => TenantSubscription::query()->orderByDesc('id')->pluck('id', 'id')->map(fn ($id) => "#{$id}")->all())),
                    DatePicker::make('effective')->label('New price from')->native(false)->required()->live(),
                    Select::make('version')->label('New price version')->required()->options(fn (Get $get) => $get('effective') ? $this->standardOptions((int) $get('subscription'), substr((string) $get('effective'), 0, 10)) : []),
                    DatePicker::make('notice_date')->label('Notice sent on')->native(false)->required()->default(now()->toDateString()),
                    TextInput::make('reference')->label('Letter or e-mail reference')->maxLength(100),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(PriceNotices::class)->record($this->inTenant(fn () => TenantSubscription::query()->findOrFail((int) $data['subscription'])),
                    PlanPriceVersion::query()->findOrFail((int) substr((string) $data['version'], 2)), substr((string) $data['notice_date'], 0, 10), substr((string) $data['effective'], 0, 10), $data['reason'],
                    auth()->user(), $data['reference'] ?? null), 'Price notice recorded')),
            Action::make('runBilling')->label('Calculate billing periods')->icon(Heroicon::OutlinedCalculator)->visible(fn () => $hasTenant() && $this->inTenant(fn () => SubscriptionBillingTerm::query()->exists()))
                ->modalDescription('Calculates the periods that are due for this tenant and drafts their invoices (nothing is issued or sent). Periods already calculated are never recalculated.')
                ->action(fn () => $this->attempt(function () {
                    $summary = app(BillingPeriods::class)->run($this->selectedTenant());
                    Notification::make()->info()->title("{$summary['created']} period(s): {$summary['drafted']} drafted, {$summary['nothing_due']} nothing due, {$summary['exceptions']} exception(s)")->send();
                }, 'Billing calculated')),
            Action::make('redraftPeriod')->label('Redraft a period')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => $hasTenant() && $this->redraftable() !== [])
                ->modalDescription('Drafts again a period whose draft was discarded, from its frozen quantity and amount (never from today\'s employee data).')
                ->schema([Select::make('period')->label('Period')->required()->options(fn () => $this->redraftable()), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(BillingPeriods::class)->redraft($this->inTenant(fn () => BillingPeriod::query()->findOrFail((int) $data['period'])),
                    $data['reason'], auth()->user()), 'Period redrafted')),
        ];
    }

    /** @return array<int, string> */
    private function redraftable(): array
    {
        return $this->periods()->filter(fn (BillingPeriod $p) => $p->status === BillingPeriod::DRAFTED && $p->invoice?->status === InvoiceStatus::Discarded)
            ->mapWithKeys(fn (BillingPeriod $p) => [$p->id => "{$p->kind->label()} {$p->period_start->toDateString()} · {$p->currency->value} {$p->amount()->toDecimal()}"])->all();
    }

    /**
     * @return array<string, string> the prices that can be pinned on $day: the customer's agreed versions in force
     *                               ("n:id", which take precedence for their interval) and the standard versions on sale ("s:id")
     */
    private function priceOptions(int $subscriptionId, string $day, bool $withAgreed = false): array
    {
        $standard = $this->standardOptions($subscriptionId, $day);
        if (! $withAgreed || $subscriptionId === 0) {
            return $standard;
        }
        $agreed = [];
        $covered = [];
        foreach ($this->deals()->where('subscription_id', $subscriptionId) as $deal) {
            $version = $this->inTenant(fn () => app(NegotiatedPrices::class)->inForce($deal, $day));
            if ($version !== null) {
                $agreed["n:{$version->id}"] = 'Agreed: '.$this->dealLabel($deal).' · '.$this->describeDeal($version);
                $covered[] = "{$deal->plan_version_id}|{$deal->market_id}|{$deal->interval->value}";
            }
        }
        $standard = array_filter($standard, fn (string $label, string $key) => ! in_array($this->standardKey((int) substr($key, 2)), $covered, true), ARRAY_FILTER_USE_BOTH);

        return $agreed + $standard;
    }

    /** @return array<string, string> the standard versions on sale on $day for the subscription's plan version in the tenant's market */
    private function standardOptions(int $subscriptionId, string $day): array
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
            ->mapWithKeys(fn (PlanPrice $p) => ($v = $catalog->versionOnSale($p, $day)) === null ? [] : ["s:{$v->id}" => "Standard: {$catalog->priceLabel($p)} · {$v->currency->value} {$v->amount()->toDecimal()} (v{$v->version})"])->all();
    }

    private function standardKey(int $versionId): string
    {
        $price = PlanPrice::query()->findOrFail(PlanPriceVersion::query()->findOrFail($versionId)->plan_price_id);

        return "{$price->plan_version_id}|{$price->market_id}|{$price->interval->value}";
    }

    private function pinnable(string $key): PlanPriceVersion|NegotiatedPriceVersion
    {
        return str_starts_with($key, 'n:')
            ? $this->inTenant(fn () => NegotiatedPriceVersion::query()->findOrFail((int) substr($key, 2)))
            : PlanPriceVersion::query()->findOrFail((int) substr($key, 2));
    }

    private function dealLabel(NegotiatedPrice $deal): string
    {
        $deal->loadMissing('market');

        $plan = $this->planLabels[$deal->plan_version_id] ??= app(BillingCatalog::class)->planLabel($deal->plan_version_id);

        return "#{$deal->subscription_id} · {$plan} · {$deal->market->code} · "
            .$deal->basis->label().", {$deal->interval->label()} · {$deal->contract_start->toDateString()} to "
            .($deal->contract_end?->toDateString() ?? 'open').($deal->contract_reference ? " · {$deal->contract_reference}" : '');
    }

    /** @return array<int, string> */
    private function dealVersionsWhere(VersionStatus $status): array
    {
        return $this->deals()->reduce(fn (array $carry, NegotiatedPrice $d) => $carry + $d->versions->where('status', $status)
            ->mapWithKeys(fn (NegotiatedPriceVersion $v) => [$v->id => $this->dealLabel($d).' · '.$this->describeDeal($v)." ({$status->value})"])->all(), []);
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
        } finally {
            [$this->deals, $this->pendingDeals] = [null, null];
        }
    }
}
