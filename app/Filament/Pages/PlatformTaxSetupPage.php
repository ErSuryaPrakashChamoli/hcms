<?php

namespace App\Filament\Pages;

use App\Domain\Billing\Models\InvoiceNumberSeries;
use App\Domain\Billing\Models\SupplierProfile;
use App\Domain\Billing\Services\InvoiceSeries;
use App\Domain\Billing\Services\SupplierProfiles;
use App\Domain\Payments\Services\ProviderRegistry;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxRuleStatus;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Services\JurisdictionCatalogue;
use App\Domain\Tax\Services\TaxEngine;
use App\Domain\Tax\Services\TaxRegistry;
use App\Domain\Tax\Services\TaxRules;
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
use RuntimeException;
use UnitEnum;

/**
 * SaaS.7: tax and invoicing setup for platform operators: the jurisdiction support matrix (what PeopleOS can
 * honestly say per country; nothing is "supported" without a tax and legal approval outside software), Markedge's
 * selling entities, commercial tax rules (drafted, submitted, verified by a second operator, retired) and invoice
 * number series. No rate, SAC, registration or series is shipped: they are decisions (B-7, B-8).
 */
class PlatformTaxSetupPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Tax & invoicing';

    protected static ?string $title = 'Tax and invoicing setup';

    protected static ?string $slug = 'platform-tax';

    protected static ?int $navigationSort = 24;

    protected string $view = 'filament.pages.platform-tax-setup';

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return list<array<string, mixed>> */
    public function matrix(): array
    {
        $engine = app(TaxEngine::class);
        $providers = array_values(array_map(fn ($p) => $p->label(), app(ProviderRegistry::class)->all()));
        $rows = [];
        foreach (JurisdictionCatalogue::all() as $key => $entry) {
            $status = $engine->status($entry['countries'][0]);
            $rows[] = ['key' => $key, 'name' => $entry['name'], 'currency' => $entry['currency'], 'regime' => $entry['regime']->label(), 'registration' => $entry['registration']->label(),
                'customers' => $entry['customers'], 'determination' => app(TaxRegistry::class)->determiner($entry['regime']) ? 'Built' : 'Not built',
                'invoice_requirements' => $entry['invoice_requirements'], 'payments' => implode('; ', $providers), 'status' => $status];
        }

        return $rows;
    }

    /** @return Collection<int, SupplierProfile> */
    public function suppliers(): Collection
    {
        return SupplierProfile::query()->orderBy('entity_code')->orderByDesc('version')->get();
    }

    /** @return Collection<int, TaxRule> */
    public function rules(): Collection
    {
        return TaxRule::query()->orderByDesc('id')->limit(100)->get();
    }

    /** @return Collection<int, InvoiceNumberSeries> */
    public function series(): Collection
    {
        return InvoiceNumberSeries::query()->orderBy('supplier_entity')->orderByDesc('starts_on')->get();
    }

    protected function getHeaderActions(): array
    {
        $reason = fn (string $label = 'Reason') => Textarea::make('reason')->label($label)->required()->minLength(5)->maxLength(500);
        $registry = app(TaxRegistry::class);
        $regimes = collect(TaxRegime::cases())->filter(fn (TaxRegime $r) => $registry->determiner($r) !== null);
        $outcomeFields = $regimes->flatMap(fn (TaxRegime $r) => collect($registry->determiner($r)->outcomes())->map(fn (string $label, string $key) => TextInput::make("outcome_{$r->value}_{$key}")
            ->label("{$key}: {$label}")->placeholder(implode(' ', [$r->taxTypes()[0], '0.00']))->helperText('Components as "TYPE rate; TYPE rate". Leave empty if the rule does not price this outcome.')
            ->visible(fn (Get $get) => $get('regime') === $r->value)))->values()->all();
        $ruleOptions = fn (TaxRuleStatus ...$statuses) => fn () => TaxRule::query()->whereIn('status', $statuses)->get()->mapWithKeys(fn (TaxRule $r) => [$r->id => "{$r->label()} · from {$r->effective_from->toDateString()}"])->all();

        return [
            Action::make('recordSupplier')->label('Record supplier entity')->icon(Heroicon::OutlinedBuildingOffice2)
                ->modalDescription('A new version of a Markedge selling entity from a date. Issued invoices keep the version they were issued under.')
                ->schema([
                    TextInput::make('entity')->label('Entity code')->required()->maxLength(32),
                    TextInput::make('legal_name')->label('Legal name')->required()->maxLength(200),
                    TextInput::make('address_line1')->label('Address')->required()->maxLength(200),
                    TextInput::make('city')->label('City')->required()->maxLength(100),
                    TextInput::make('postal_code')->label('Postal code')->maxLength(20),
                    TextInput::make('country')->label('Country (ISO code)')->required()->length(2)->live(onBlur: true),
                    Select::make('subdivision')->label('State or province')->options(fn (Get $get) => $registry->subdivisions(strtoupper((string) $get('country'))))->searchable(),
                    Select::make('tax_id_type')->label('Tax registration type')->options(collect(TaxIdType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()),
                    TextInput::make('tax_id_value')->label('Tax registration number')->maxLength(32),
                    DatePicker::make('from')->label('From')->native(false)->required()->default(now()->toDateString()),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(SupplierProfiles::class)->record($data['entity'], $data, substr((string) $data['from'], 0, 10), $data['reason'], auth()->user()), 'Supplier recorded')),
            Action::make('draftRule')->label('Draft tax rule')->icon(Heroicon::OutlinedDocumentPlus)->visible($regimes->isNotEmpty())
                ->modalDescription('Rates and classifications come from a tax review; nothing is pre-filled. A rule applies only after another operator verifies it.')
                ->schema(array_merge([
                    Select::make('regime')->label('Regime')->required()->live()->options($regimes->mapWithKeys(fn (TaxRegime $r) => [$r->value => $r->label()])->all()),
                    TextInput::make('country')->label('Country (ISO code)')->required()->length(2),
                    TextInput::make('category')->label('Tax category')->required()->default('peopleos.subscription'),
                    DatePicker::make('from')->label('Effective from')->native(false)->required()->default(now()->toDateString()),
                ], $outcomeFields, [
                    Select::make('rounding')->label('Rounding of each tax amount')->required()->options(['half_up' => 'Half up', 'half_even' => 'Half even (banker\'s)']),
                    TextInput::make('classification')->label('Classification (key=value; …, e.g. the SAC)')->placeholder('sac=000000'),
                    $reason(),
                ]))
                ->action(fn (array $data) => $this->attempt(fn () => app(TaxRules::class)->draft(TaxRegime::from($data['regime']), $data['country'], null, $data['category'],
                    substr((string) $data['from'], 0, 10), $this->outcomes($data), $data['rounding'], $this->classification((string) ($data['classification'] ?? '')), $data['reason'], auth()->user()), 'Tax rule drafted')),
            Action::make('submitRule')->label('Submit for review')->icon(Heroicon::OutlinedPaperAirplane)->visible(fn () => TaxRule::query()->where('status', TaxRuleStatus::Draft)->exists())
                ->schema([Select::make('rule')->label('Draft rule')->required()->options($ruleOptions(TaxRuleStatus::Draft)), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(TaxRules::class)->submit(TaxRule::query()->findOrFail((int) $data['rule']), $data['reason'], auth()->user()), 'Submitted for review')),
            Action::make('verifyRule')->label('Verify rule')->icon(Heroicon::OutlinedShieldCheck)->color('success')->visible(fn () => TaxRule::query()->where('status', TaxRuleStatus::Review)->exists())
                ->modalDescription('Only an operator other than its author and submitter can verify a rule, with a reference to the tax review.')
                ->schema([Select::make('rule')->label('Rule in review')->required()->options($ruleOptions(TaxRuleStatus::Review)),
                    TextInput::make('reference')->label('Tax review reference')->required()->minLength(5)->maxLength(200), Textarea::make('notes')->label('Notes')->maxLength(1000)])
                ->action(fn (array $data) => $this->attempt(fn () => app(TaxRules::class)->verify(TaxRule::query()->findOrFail((int) $data['rule']), $data['reference'], $data['notes'] ?? null, auth()->user()), 'Rule verified')),
            Action::make('retireRule')->label('Retire rule')->icon(Heroicon::OutlinedArchiveBox)->color('gray')
                ->visible(fn () => TaxRule::query()->whereIn('status', [TaxRuleStatus::Draft, TaxRuleStatus::Review, TaxRuleStatus::Verified])->exists())
                ->modalDescription('A retired rule stops applying: invoices that would need it are refused until another is verified.')
                ->schema([Select::make('rule')->label('Rule')->required()->options($ruleOptions(TaxRuleStatus::Draft, TaxRuleStatus::Review, TaxRuleStatus::Verified)), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(TaxRules::class)->retire(TaxRule::query()->findOrFail((int) $data['rule']), $data['reason'], auth()->user()), 'Rule retired')),
            Action::make('createSeries')->label('New number series')->icon(Heroicon::OutlinedHashtag)->visible(fn () => SupplierProfile::query()->exists())
                ->modalDescription('Gap-free numbers for one entity over an explicit window (e.g. a financial year). Open series never overlap.')
                ->schema([
                    Select::make('entity')->label('Entity')->required()->options(fn () => SupplierProfile::query()->distinct()->pluck('entity_code', 'entity_code')->all()),
                    TextInput::make('prefix')->label('Prefix')->required()->maxLength(16)->placeholder('PO/2027-28/'),
                    DatePicker::make('starts_on')->label('First day')->native(false)->required(),
                    DatePicker::make('ends_on')->label('Last day')->native(false)->required(),
                    TextInput::make('padding')->label('Sequence digits')->numeric()->required()->minValue(1)->maxValue(12)->default(5),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(InvoiceSeries::class)->create($data['entity'], $data['prefix'], substr((string) $data['starts_on'], 0, 10),
                    substr((string) $data['ends_on'], 0, 10), (int) $data['padding'], $data['reason'], auth()->user()), 'Series created')),
            Action::make('closeSeries')->label('Close series')->icon(Heroicon::OutlinedLockClosed)->color('gray')->visible(fn () => InvoiceNumberSeries::query()->where('status', 'open')->exists())
                ->schema([Select::make('series')->label('Series')->required()->options(fn () => InvoiceNumberSeries::query()->where('status', 'open')->get()->mapWithKeys(fn ($s) => [$s->id => "{$s->supplier_entity} · {$s->label()}"])->all()), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(InvoiceSeries::class)->close(InvoiceNumberSeries::query()->findOrFail((int) $data['series']), $data['reason'], auth()->user()), 'Series closed')),
        ];
    }

    /** @return array<string, list<array{type: string, rate: string}>> */
    private function outcomes(array $data): array
    {
        $regime = (string) $data['regime'];
        $outcomes = [];
        foreach ($data as $field => $value) {
            if (! str_starts_with($field, "outcome_{$regime}_") || blank($value)) {
                continue;
            }
            $key = substr($field, strlen("outcome_{$regime}_"));
            $outcomes[$key] = trim((string) $value) === 'none' ? [] : array_map(function (string $part) {
                $bits = preg_split('/\s+/', trim($part));
                if (count($bits) !== 2) {
                    throw new RuntimeException("\"{$part}\" is not a component (TYPE rate).");
                }

                return ['type' => $bits[0], 'rate' => $bits[1]];
            }, array_values(array_filter(explode(';', (string) $value), fn ($p) => trim($p) !== '')));
        }

        return $outcomes;
    }

    /** @return array<string, string> */
    private function classification(string $value): array
    {
        $pairs = [];
        foreach (array_filter(array_map('trim', explode(';', $value))) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if (trim($k) !== '' && trim($v) !== '') {
                $pairs[strtolower(trim($k))] = trim($v);
            }
        }

        return $pairs;
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
