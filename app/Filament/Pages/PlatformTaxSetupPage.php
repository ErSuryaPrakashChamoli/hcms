<?php

namespace App\Filament\Pages;

use App\Domain\Billing\Models\ConfigurationVersion;
use App\Domain\Billing\Models\InvoiceNumberSeries;
use App\Domain\Billing\Models\SupplierProfile;
use App\Domain\Billing\Services\InvoiceSeries;
use App\Domain\Billing\Services\StatutoryDataset;
use App\Domain\Billing\Services\SupplierProfiles;
use App\Domain\Payments\Services\ProviderRegistry;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxRuleState;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

/**
 * SaaS.7: tax and invoicing setup for platform operators: the jurisdiction support matrix (what PeopleOS can
 * honestly say per country; nothing is "supported" without a tax and legal approval outside software), Markedge's
 * selling entities, commercial tax rules (drafted, submitted, verified by a second operator, retired) and invoice
 * number series. No rate, SAC, registration or series is shipped: they are decisions (B-7, B-8).
 *
 * SaaS.7 configuration: the statutory dataset (current rules of India, the UK, the EU member states, the UAE and the
 * researched US states, with sources) is loaded by one operator and verified by another; pending values stay
 * pending. Each rule shows its state (current, scheduled, superseded, expired, pending verification, rejected,
 * retired), dates, conditions, required registrations and source; a change is a new version (amend), never an edit.
 * The coverage tables show every US state and EU member state, configured or pending (never 0 %).
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
        $registry = app(TaxRegistry::class);
        $providers = array_values(array_map(fn ($p) => $p->label(), app(ProviderRegistry::class)->all()));
        $rows = [];
        foreach (JurisdictionCatalogue::all() as $key => $entry) {
            $status = $engine->status($entry['countries'][0]);
            $built = array_filter([$registry->determiner($entry['regime']) ? 'supplier side' : null, $registry->destination($entry['regime']) ? 'customer (destination) side' : null]);
            $rows[] = ['key' => $key, 'name' => $entry['name'], 'currency' => $entry['currency'], 'regime' => $entry['regime']->label(), 'registration' => $entry['registration']->label(),
                'customers' => $entry['customers'], 'determination' => $built === [] ? 'Not built' : 'Built: '.implode(', ', $built),
                'invoice_requirements' => $entry['invoice_requirements'], 'payments' => implode('; ', $providers), 'status' => $status];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> the shipped statutory datasets with what is loaded, verified and pending */
    public function datasets(): array
    {
        $service = app(StatutoryDataset::class);

        return array_map(fn (string $v) => ['version' => $v, 'current' => $v === config('peopleos.commercial.statutory_dataset.current')] + $service->status($v), $service->versions());
    }

    /** @var array<int, TaxRuleState>|null per-request memo: every rule's state, derived in one pass */
    private ?array $states = null;

    public function ruleState(TaxRule $rule): TaxRuleState
    {
        $this->states ??= app(TaxRules::class)->states(TaxRule::query()->get());

        return $this->states[$rule->id] ?? app(TaxRules::class)->state($rule);
    }

    /** @return list<string> one line per outcome: treatment, components, conditions, registration required */
    public function outcomeLines(TaxRule $rule): array
    {
        $lines = [];
        foreach (array_keys($rule->outcomes ?? []) as $key) {
            $o = $rule->outcome($key);
            $components = $o['components'] === [] ? 'no tax' : collect($o['components'])->map(fn ($c) => "{$c->type} {$c->rate} %")->implode(' + ');
            $lines[] = "{$key}: ".($o['treatment']?->value ? "{$o['treatment']->value}, " : '').$components
                .($o['taxable_percent'] !== null ? " on {$o['taxable_percent']} % of the price" : '')
                .($o['requires_supplier_registration'] ? " · needs supplier registration {$o['requires_supplier_registration']}" : '')
                .($o['conditions'] !== [] ? ' · if '.collect($o['conditions'])->map(fn ($v, $k) => $k.'='.(is_array($v) ? implode('|', $v) : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v)))->implode(', ') : '')
                .($o['wording'] ? " · wording \"{$o['wording']}\"" : '');
        }

        return $lines;
    }

    /**
     * Every US state and EU member state: the rule in force or scheduled, or PENDING VERIFICATION (no rule: invoices
     * to it are refused, never taxed at 0 %).
     *
     * @return array<string, list<array{code: string, name: string, state: string, color: string, rule: ?string, summary: string}>>
     */
    public function coverage(): array
    {
        $rules = TaxRule::query()->whereIn('regime', [TaxRegime::UsSalesTax, TaxRegime::EuVat])->whereNotIn('status', [TaxRuleStatus::Rejected, TaxRuleStatus::Retired])->get();
        $row = function (string $code, string $name, $candidates) {
            $states = $candidates->map(fn (TaxRule $r) => [$r, $this->ruleState($r)]);
            $pick = $states->first(fn ($p) => $p[1] === TaxRuleState::Current) ?? $states->first(fn ($p) => $p[1] === TaxRuleState::Scheduled)
                ?? $states->first(fn ($p) => $p[1] === TaxRuleState::PendingVerification) ?? $states->first(fn ($p) => $p[1] === TaxRuleState::Draft);
            $next = $states->first(fn ($p) => $p[1] === TaxRuleState::Scheduled && $p[0]->id !== ($pick[0]->id ?? null));

            return $pick === null
                ? ['code' => $code, 'name' => $name, 'state' => 'PENDING VERIFICATION', 'color' => 'warning', 'rule' => null, 'summary' => 'No rule: invoices to customers here are refused.']
                : ['code' => $code, 'name' => $name, 'state' => $pick[1]->label(), 'color' => $pick[1]->color(), 'rule' => $pick[0]->rule_code ?? $pick[0]->label(),
                    'summary' => implode(' / ', $this->outcomeLines($pick[0])).($next ? " · then {$next[0]->rule_code} from {$next[0]->effective_from->toDateString()}" : '')];
        };
        $us = [];
        foreach (app(TaxRegistry::class)->subdivisions('US') as $code => $name) {
            $us[] = $row($code, $name, $rules->where('regime', TaxRegime::UsSalesTax)->where('subdivision', $code));
        }
        $eu = [];
        foreach (JurisdictionCatalogue::EU_MEMBERS as $code) {
            $eu[] = $row($code, $code, $rules->where('regime', TaxRegime::EuVat)->where('country', $code));
        }

        return ['United States (state level; local rates are not configured)' => $us, 'European Union member states' => $eu];
    }

    /** @return Collection<int, ConfigurationVersion> statutory parameters (e.g. the invoice-number length) with their versions */
    public function parameters(): Collection
    {
        return ConfigurationVersion::query()->where('domain', 'statutory')->orderBy('key')->orderBy('scope')->orderByDesc('version')->get();
    }

    /** @return Collection<int, SupplierProfile> */
    public function suppliers(): Collection
    {
        return SupplierProfile::query()->orderBy('entity_code')->orderByDesc('version')->get();
    }

    /** @return Collection<int, TaxRule> */
    public function rules(): Collection
    {
        return TaxRule::query()->orderBy('country')->orderBy('subdivision')->orderBy('regime')->orderBy('tax_category')->orderByDesc('version')->limit(300)->get();
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
        $regimes = collect(TaxRegime::cases())->filter(fn (TaxRegime $r) => $registry->outcomes($r) !== []);
        $outcomeFields = $regimes->flatMap(fn (TaxRegime $r) => collect($registry->outcomes($r))->map(fn (string $label, string $key) => TextInput::make("outcome_{$r->value}_{$key}")
            ->label("{$key}: {$label}")->placeholder(implode(' ', [$r->taxTypes()[0], '0.00']))->helperText('Components as "TYPE rate; TYPE rate", or "none". Leave empty if the rule does not price this outcome.')
            ->visible(fn (Get $get) => $get('regime') === $r->value)))->values()->all();
        $ruleOptions = fn (TaxRuleStatus ...$statuses) => fn () => TaxRule::query()->whereIn('status', $statuses)->orderBy('country')->orderBy('subdivision')->get()
            ->mapWithKeys(fn (TaxRule $r) => [$r->id => ($r->rule_code ? "{$r->rule_code} · " : '')."{$r->label()} · from {$r->effective_from->toDateString()}"])->all();
        $sourceFields = fn () => [
            TextInput::make('source')->label('Source (authority)')->maxLength(150)->placeholder('CBIC, HMRC, European Commission (TEDB), FTA, state revenue department'),
            TextInput::make('source_reference')->label('Legal reference')->maxLength(500),
            TextInput::make('source_url')->label('Source URL')->url()->maxLength(500),
            DatePicker::make('source_date')->label('Source date')->native(false),
        ];
        $datasets = fn () => array_combine(app(StatutoryDataset::class)->versions(), app(StatutoryDataset::class)->versions());

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
                    Textarea::make('registrations')->label('Registrations and undertakings (optional)')->rows(3)
                        ->placeholder("IN_LUT AD270326000001X 2026-04-01 2027-03-31\nUS_STATE:US-TX 32000000000 2027-01-01")
                        ->helperText('One per line: TYPE reference valid-from [valid-to]. Types tax conditions require: IN_LUT, GB_VAT, EU_OSS_NON_UNION, AE_TRN, US_STATE:US-XX.'),
                    DatePicker::make('from')->label('From')->native(false)->required()->default(now()->toDateString()),
                    $reason(),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(SupplierProfiles::class)->record($data['entity'], ['registrations' => $this->registrations((string) ($data['registrations'] ?? ''))] + $data,
                    substr((string) $data['from'], 0, 10), $data['reason'], auth()->user()), 'Supplier recorded')),
            Action::make('loadDataset')->label('Load statutory dataset')->icon(Heroicon::OutlinedArrowDownTray)->visible(fn () => $datasets() !== [])
                ->modalDescription('Loads the current statutory values shipped with PeopleOS (with their official sources) as rules pending verification. Nothing applies until another operator verifies the dataset; values the dataset marks pending stay pending.')
                ->schema([Select::make('version')->label('Dataset')->required()->options($datasets)->default(config('peopleos.commercial.statutory_dataset.current')), $reason()])
                ->action(fn (array $data) => $this->attempt(function () use ($data) {
                    $counts = app(StatutoryDataset::class)->load($data['version'], $data['reason'], auth()->user());
                    Notification::make()->info()->title("{$counts['rules']} rules and {$counts['parameters']} parameters loaded, pending verification")->send();
                }, 'Dataset loaded')),
            Action::make('activateDataset')->label('Verify statutory dataset')->icon(Heroicon::OutlinedShieldCheck)->color('success')
                ->visible(fn () => TaxRule::query()->whereNotNull('dataset_version')->where('status', TaxRuleStatus::Review)->exists()
                    || ConfigurationVersion::query()->whereNotNull('dataset_version')->where('status', ConfigurationVersion::PENDING)->exists())
                ->modalDescription('Maker-checker: an operator other than the one who loaded it verifies the dataset against its sources. Its source-verified values then apply from their effective dates; pending values stay pending.')
                ->schema([Select::make('version')->label('Dataset')->required()->options($datasets)->default(config('peopleos.commercial.statutory_dataset.current')),
                    TextInput::make('reference')->label('Verification reference')->required()->minLength(5)->maxLength(200)])
                ->action(fn (array $data) => $this->attempt(function () use ($data) {
                    $counts = app(StatutoryDataset::class)->activate($data['version'], $data['reference'], auth()->user());
                    Notification::make()->info()->title("{$counts['verified']} values verified; {$counts['pending']} stay pending verification")->send();
                }, 'Dataset verified')),
            Action::make('draftRule')->label('Draft tax rule')->icon(Heroicon::OutlinedDocumentPlus)->visible($regimes->isNotEmpty())
                ->modalDescription('Rates and classifications come from a tax review or an official source; nothing is pre-filled. A rule applies only after another operator verifies it.')
                ->schema(array_merge([
                    Select::make('regime')->label('Regime')->required()->live()->options($regimes->mapWithKeys(fn (TaxRegime $r) => [$r->value => $r->label()])->all()),
                    TextInput::make('country')->label('Country (ISO code)')->required()->length(2)->live(onBlur: true),
                    Select::make('subdivision')->label('State (US rules are per state)')->searchable()->options(fn (Get $get) => $registry->subdivisions(strtoupper((string) $get('country'))))
                        ->visible(fn (Get $get) => $get('regime') === TaxRegime::UsSalesTax->value),
                    TextInput::make('category')->label('Tax category')->required()->default('peopleos.subscription'),
                    TextInput::make('rule_code')->label('Rule code (optional)')->maxLength(64)->placeholder('IN-GST-9983-18'),
                    DatePicker::make('from')->label('Effective from')->native(false)->required()->default(now()->toDateString()),
                    DatePicker::make('to')->label('Expires after (optional)')->native(false),
                ], $outcomeFields, [
                    Textarea::make('outcomes_json')->label('Outcomes as JSON (advanced, optional: replaces the fields above)')->rows(4)
                        ->helperText('{"outcome": {"components": [{"type": "VAT", "rate": "20"}], "treatment": "standard", "conditions": {…}, "requires_supplier_registration": "GB_VAT", "wording": "…"}}'),
                    Select::make('rounding')->label('Rounding of each tax amount')->required()->options(['half_up' => 'Half up', 'half_even' => 'Half even (banker\'s)']),
                    TextInput::make('classification')->label('Classification (key=value; …, e.g. the SAC)')->placeholder('sac=000000'),
                ], $sourceFields(), [$reason()]))
                ->action(fn (array $data) => $this->attempt(fn () => app(TaxRules::class)->draft(TaxRegime::from($data['regime']), $data['country'], $data['subdivision'] ?? null, $data['category'],
                    substr((string) $data['from'], 0, 10), $this->outcomes($data), $data['rounding'], $this->classification((string) ($data['classification'] ?? '')), $data['reason'], auth()->user(),
                    $this->details($data)), 'Tax rule drafted')),
            Action::make('amendRule')->label('New rule version')->icon(Heroicon::OutlinedDocumentDuplicate)
                ->visible(fn () => TaxRule::query()->whereIn('status', [TaxRuleStatus::Verified, TaxRuleStatus::Review])->exists())
                ->modalDescription('A change of law or policy is a new version of the rule from a date (the current one stays for earlier invoices and is superseded from that date). Pre-filled from the chosen rule; verified by another operator before it applies.')
                ->schema(array_merge([
                    Select::make('rule')->label('Rule to amend')->required()->live()->options($ruleOptions(TaxRuleStatus::Verified, TaxRuleStatus::Review))
                        ->afterStateUpdated(function ($state, Set $set) {
                            $rule = $state ? TaxRule::query()->find((int) $state) : null;
                            $set('outcomes_json', $rule ? json_encode($rule->outcomes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
                            $set('rule_code', $rule?->rule_code);
                            $set('classification', $rule ? collect($rule->classification ?? [])->map(fn ($v, $k) => "{$k}={$v}")->implode('; ') : null);
                        }),
                    TextInput::make('rule_code')->label('Rule code of the new version')->maxLength(64),
                    DatePicker::make('from')->label('New version effective from')->native(false)->required(),
                    DatePicker::make('to')->label('Expires after (optional)')->native(false),
                    Textarea::make('outcomes_json')->label('Outcomes (JSON)')->required()->rows(8),
                    TextInput::make('classification')->label('Classification (key=value; …)'),
                ], $sourceFields(), [$reason()]))
                ->action(fn (array $data) => $this->attempt(function () use ($data) {
                    $rule = TaxRule::query()->findOrFail((int) $data['rule']);
                    app(TaxRules::class)->draft($rule->regime, $rule->country, $rule->subdivision !== '' ? $rule->subdivision : null, $rule->tax_category, substr((string) $data['from'], 0, 10),
                        $this->json((string) $data['outcomes_json']), $rule->rounding_mode->value, $this->classification((string) ($data['classification'] ?? '')), $data['reason'], auth()->user(),
                        $this->details($data) + ['amount_basis' => $rule->amount_basis, 'conditions' => $rule->conditions]);
                }, 'New rule version drafted: submit it for review')),
            Action::make('submitRule')->label('Submit for review')->icon(Heroicon::OutlinedPaperAirplane)->visible(fn () => TaxRule::query()->where('status', TaxRuleStatus::Draft)->exists())
                ->schema([Select::make('rule')->label('Draft rule')->required()->options($ruleOptions(TaxRuleStatus::Draft)), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(TaxRules::class)->submit(TaxRule::query()->findOrFail((int) $data['rule']), $data['reason'], auth()->user()), 'Submitted for review')),
            Action::make('verifyRule')->label('Verify rule')->icon(Heroicon::OutlinedShieldCheck)->color('success')->visible(fn () => TaxRule::query()->where('status', TaxRuleStatus::Review)->exists())
                ->modalDescription('Only an operator other than its author and submitter can verify a rule, with a reference to the tax review.')
                ->schema([Select::make('rule')->label('Rule in review')->required()->options($ruleOptions(TaxRuleStatus::Review)),
                    TextInput::make('reference')->label('Tax review reference')->required()->minLength(5)->maxLength(200), Textarea::make('notes')->label('Notes')->maxLength(1000)])
                ->action(fn (array $data) => $this->attempt(fn () => app(TaxRules::class)->verify(TaxRule::query()->findOrFail((int) $data['rule']), $data['reference'], $data['notes'] ?? null, auth()->user()), 'Rule verified')),
            Action::make('rejectRule')->label('Reject rule')->icon(Heroicon::OutlinedXCircle)->color('danger')->visible(fn () => TaxRule::query()->where('status', TaxRuleStatus::Review)->exists())
                ->modalDescription('A checker (not its author or submitter) sends a rule back: it never applies. A corrected rule is a new version.')
                ->schema([Select::make('rule')->label('Rule in review')->required()->options($ruleOptions(TaxRuleStatus::Review)), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(TaxRules::class)->reject(TaxRule::query()->findOrFail((int) $data['rule']), $data['reason'], auth()->user()), 'Rule rejected')),
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

    /** @return array<string, mixed> */
    private function outcomes(array $data): array
    {
        if (! blank($data['outcomes_json'] ?? null)) {
            return $this->json((string) $data['outcomes_json']);
        }
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

    /** @return array<string, mixed> */
    private function json(string $value): array
    {
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('The outcomes are not valid JSON (an object of outcome => definition).');
        }

        return $decoded;
    }

    /** @return array<string, mixed> expiry, rule code and source of a drafted rule */
    private function details(array $data): array
    {
        return ['effective_to' => blank($data['to'] ?? null) ? null : substr((string) $data['to'], 0, 10), 'rule_code' => blank($data['rule_code'] ?? null) ? null : strtoupper(trim((string) $data['rule_code'])),
            'source' => $data['source'] ?? null, 'source_reference' => $data['source_reference'] ?? null, 'source_url' => $data['source_url'] ?? null,
            'source_date' => blank($data['source_date'] ?? null) ? null : substr((string) $data['source_date'], 0, 10)];
    }

    /** @return list<array{type: string, reference: string, valid_from: string, valid_to: ?string}> one per line: TYPE reference from [to] */
    private function registrations(string $value): array
    {
        $rows = [];
        foreach (array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])) as $line) {
            $bits = preg_split('/\s+/', $line);
            if (count($bits) < 3 || count($bits) > 4) {
                throw new RuntimeException("\"{$line}\" is not a registration (TYPE reference valid-from [valid-to]).");
            }
            $rows[] = ['type' => strtoupper($bits[0]), 'reference' => $bits[1], 'valid_from' => $bits[2], 'valid_to' => $bits[3] ?? null];
        }

        return $rows;
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
        } finally {
            $this->states = null;
        }
    }
}
