<?php

namespace App\Filament\Pages;

use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Enums\ApprovalStatus;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\PricingBasis;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\MoneyFormatter;
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
use RuntimeException;
use UnitEnum;

/**
 * SaaS.7: the billing catalogue for platform operators: the currency catalogue, markets (one currency each) and
 * prices per plan version × market × interval with their versions. Every change goes through BillingCatalog
 * (operator-only, reasoned, audited); published price versions never change. No market or price exists until an
 * operator creates it: amounts, markets and intervals are business decisions.
 *
 * SaaS.7 configuration: the price matrix (plan version × market × interval) shows each combination as CURRENT,
 * SCHEDULED or NO PRICE CONFIGURED, and each version its state (draft, pending approval, scheduled, current,
 * superseded, retired) with the dates it is on sale. Customer-specific prices are on Billing accounts.
 */
class PlatformBillingCatalogPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Billing catalogue';

    protected static ?string $title = 'Billing catalogue: markets and prices';

    protected static ?string $slug = 'platform-billing-catalog';

    protected static ?int $navigationSort = 23;

    protected string $view = 'filament.pages.platform-billing-catalog';

    private ?Collection $prices = null;

    private ?array $pending = null;

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

    /** @return Collection<int, BillingMarket> */
    public function markets(): Collection
    {
        return BillingMarket::query()->orderBy('code')->get();
    }

    /** @return Collection<int, PlanPrice> with plan version, market and versions */
    public function prices(): Collection
    {
        return $this->prices ??= PlanPrice::query()->with(['planVersion.plan', 'market', 'versions'])->orderBy('market_id')->orderBy('plan_version_id')->get();
    }

    public function money(PlanPriceVersion $version, BillingMarket $market): string
    {
        return MoneyFormatter::format(Money::ofMinor($version->unit_amount_minor, $version->currency), $market->locale);
    }

    /** The version on sale today: the latest started non-draft version, unless it is retired (as BillingCatalog::versionOnSale). */
    public function onSaleToday(PlanPrice $price): ?PlanPriceVersion
    {
        $today = $this->today();
        $latest = $price->versions
            ->filter(fn (PlanPriceVersion $v) => $v->status !== VersionStatus::Draft && $v->effective_from !== null && $v->effective_from->toDateString() <= $today)
            ->sortByDesc(fn (PlanPriceVersion $v) => $v->effective_from->toDateString())->first();

        return $latest?->status === VersionStatus::Published ? $latest : null;
    }

    /** The price matrix today: every published plan version × market × interval (CURRENT, SCHEDULED or NO_PRICE_CONFIGURED). */
    public function matrix(): array
    {
        return app(BillingCatalog::class)->matrix($this->today());
    }

    /** DRAFT, PENDING_APPROVAL, SCHEDULED, CURRENT, SUPERSEDED or RETIRED today. */
    public function versionState(PlanPriceVersion $version): string
    {
        $this->pending ??= FinancialApproval::query()->where(['action' => ApprovalAction::PricePublication, 'status' => ApprovalStatus::Pending])->pluck('subject_id')->all();

        return app(BillingCatalog::class)->versionState($version, $this->today(), in_array($version->id, $this->pending, false));
    }

    public function versionEnds(PlanPriceVersion $version): ?string
    {
        return $version->status === VersionStatus::Published ? app(BillingCatalog::class)->versionEnds($version) : null;
    }

    public static function stateColor(string $state): string
    {
        return match ($state) {
            'CURRENT' => 'success', 'SCHEDULED', 'PENDING_APPROVAL' => 'warning', 'NO_PRICE_CONFIGURED', 'EXPIRED' => 'danger', default => 'gray',
        };
    }

    protected function getHeaderActions(): array
    {
        $reason = fn () => Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500);
        $service = fn () => app(BillingCatalog::class);
        $marketFields = fn () => [
            TextInput::make('name')->label('Market name')->required()->maxLength(120),
            TextInput::make('countries')->label('Countries it is meant for (ISO codes, comma-separated)')->required()->placeholder('IN')->helperText('Informational, for a future pricing page. The billing jurisdiction is on each billing profile.'),
            TextInput::make('supplier_entity')->label('Selling Markedge entity (code)')->required()->maxLength(32),
            TextInput::make('locale')->label('Display locale')->required()->placeholder('en_IN')->helperText('Only how amounts are shown; never the currency.'),
        ];

        return [
            Action::make('createMarket')->label('New market')->icon(Heroicon::OutlinedGlobeAlt)
                ->modalDescription('A market sells in one currency. Its code and currency are permanent.')
                ->schema(array_merge([TextInput::make('code')->label('Code')->required()->maxLength(16), Select::make('currency')->label('Currency')->required()->options(Currency::options())], $marketFields(), [$reason()]))
                ->action(fn (array $data) => $this->attempt(fn () => $service()->createMarket($data['code'], $data['name'], $data['currency'], $this->countries($data['countries']),
                    $data['supplier_entity'], $data['locale'], $data['reason'], auth()->user()), 'Market created')),
            Action::make('updateMarket')->label('Edit market')->icon(Heroicon::OutlinedPencilSquare)->visible(fn () => BillingMarket::query()->exists())
                ->schema(array_merge([Select::make('market')->label('Market')->required()->options(fn () => $this->markets()->mapWithKeys(fn ($m) => [$m->id => $m->label()])->all())], $marketFields(), [$reason()]))
                ->action(fn (array $data) => $this->attempt(fn () => $service()->updateMarket(BillingMarket::query()->findOrFail((int) $data['market']), $data['name'],
                    $this->countries($data['countries']), $data['supplier_entity'], $data['locale'], $data['reason'], auth()->user()), 'Market updated')),
            Action::make('createPrice')->label('New price')->icon(Heroicon::OutlinedTag)->visible(fn () => BillingMarket::query()->exists())
                ->modalDescription('A price is one published plan version in one market for one interval. Its amounts are versions.')
                ->schema([
                    Select::make('plan_version')->label('Published plan version')->required()->options(fn () => PlanVersion::query()->with('plan')->where('status', VersionStatus::Published)->get()->mapWithKeys(fn ($v) => [$v->id => $v->label()])->all()),
                    Select::make('market')->label('Market')->required()->options(fn () => $this->markets()->mapWithKeys(fn ($m) => [$m->id => $m->label()])->all()),
                    Select::make('interval')->label('Interval')->required()->options(collect(BillingInterval::cases())->mapWithKeys(fn ($i) => [$i->value => $i->label()])->all()),
                    Select::make('basis')->label('Basis')->required()->options(collect(PricingBasis::cases())->mapWithKeys(fn ($b) => [$b->value => $b->label()])->all()),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->createPrice(PlanVersion::query()->findOrFail((int) $data['plan_version']), BillingMarket::query()->findOrFail((int) $data['market']),
                    $data['interval'], $data['basis'], $data['reason'], auth()->user()), 'Price created')),
            Action::make('draftPriceVersion')->label('Draft amount')->icon(Heroicon::OutlinedDocumentPlus)->visible(fn () => $this->prices()->isNotEmpty())
                ->schema([
                    Select::make('price')->label('Price')->required()->options(fn () => $this->prices()->mapWithKeys(fn ($p) => [$p->id => app(BillingCatalog::class)->priceLabel($p)])->all()),
                    TextInput::make('amount')->label('Unit amount in the market currency (major units)')->required()->placeholder('amount in major units, e.g. 0000.00')
                        ->helperText('Per employee per month for a per-employee price, whatever the interval; per billing interval for a fixed price. Exact to the currency\'s decimals (JPY none, BHD three). Never converted from another market.'),
                    TextInput::make('minimum')->label('Minimum quantity (employees, optional)')->numeric()->minValue(0)->default(0),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->draftPriceVersion(PlanPrice::query()->findOrFail((int) $data['price']), (string) $data['amount'], $data['reason'],
                    auth()->user(), (int) ($data['minimum'] ?? 0)), 'Amount drafted')),
            Action::make('requestPublication')->label('Request publication')->icon(Heroicon::OutlinedRocketLaunch)->color('success')
                ->visible(fn () => PlanPriceVersion::query()->where('status', VersionStatus::Draft)->exists())
                ->modalDescription('Maker-checker: another operator approves the publication on the Approvals page. Once published from the date (today or later) it never changes, and existing billing terms keep the version they pinned.')
                ->schema([
                    Select::make('version')->label('Draft')->required()->options(fn () => $this->versionsWhere(VersionStatus::Draft)),
                    DatePicker::make('from')->label('On sale from')->native(false)->required()->default(now()->toDateString()),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->requestPublication(PlanPriceVersion::query()->findOrFail((int) $data['version']), substr((string) $data['from'], 0, 10),
                    $data['reason'], auth()->user()), 'Publication requested: another operator approves it')),
            Action::make('retirePriceVersion')->label('Retire amount')->icon(Heroicon::OutlinedArchiveBox)->color('gray')
                ->visible(fn () => PlanPriceVersion::query()->whereIn('status', [VersionStatus::Draft, VersionStatus::Published])->exists())
                ->modalDescription('A published version stops being on sale (terms already pinned to it keep it); a draft is abandoned.')
                ->schema([Select::make('version')->label('Version')->required()->options(fn () => $this->versionsWhere(VersionStatus::Draft) + $this->versionsWhere(VersionStatus::Published)), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->retirePriceVersion(PlanPriceVersion::query()->findOrFail((int) $data['version']), $data['reason'], auth()->user()), 'Amount retired')),
        ];
    }

    /** @return array<int, string> */
    private function versionsWhere(VersionStatus $status): array
    {
        return PlanPriceVersion::query()->with('price.planVersion.plan', 'price.market')->where('status', $status)->get()
            ->mapWithKeys(fn (PlanPriceVersion $v) => [$v->id => app(BillingCatalog::class)->priceLabel($v->price)." · v{$v->version} · {$v->currency->value} {$v->amount()->toDecimal()}"
                .($v->minimum_quantity > 0 ? " · minimum {$v->minimum_quantity}" : '')." ({$status->value})"])->all();
    }

    /** @return list<string> */
    private function countries(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
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
            $this->prices = null;
            $this->pending = null;
        }
    }
}
