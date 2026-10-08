<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Models\ConfigurationVersion;
use App\Domain\Billing\Services\BillingDirectory;
use App\Domain\Billing\Services\CommercialConfiguration;
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
 * SaaS.7 configuration: the values behind billing that are not prices or tax rates, kept apart by kind. Markedge
 * policy (payment terms, B2B only, price-increase notice, tax-exclusive prices, proration rounding, where TDS may be
 * deducted) shows its value in force, where it comes from (an approved version or the shipped default) and every
 * version; statutory parameters (the invoice-number length) show their source. A change is proposed from a date and
 * applied only when another operator approves it on the Approvals page; nothing is edited in place. Customer terms
 * live on Billing accounts (negotiated prices). The trail lists every configuration change with maker and checker.
 */
class PlatformCommercialPoliciesPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Commercial policies';

    protected static ?string $title = 'Commercial policies and statutory parameters';

    protected static ?string $slug = 'platform-commercial-policies';

    protected static ?int $navigationSort = 29;

    protected string $view = 'filament.pages.platform-commercial-policies';

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * One row per key and scope: the value in force today, where it comes from and every version with its state.
     *
     * @return list<array{key: ConfigurationKey, scope: string, value: string, source: string, versions: list<array{version: ConfigurationVersion, state: string}>}>
     */
    public function rows(string $domain): array
    {
        $configuration = app(CommercialConfiguration::class);
        $rows = [];
        foreach (ConfigurationKey::cases() as $key) {
            if ($key->domain() !== $domain) {
                continue;
            }
            foreach ($key->scoped() ? ($configuration->scopes($key) ?: ['']) : [''] as $scope) {
                $resolved = $scope === '' && $key->scoped() ? ['value' => null, 'source' => 'not_configured', 'version' => null] : $configuration->resolve($key, $scope);
                $rows[] = ['key' => $key, 'scope' => $scope, 'value' => $resolved['value'] === null ? 'NOT CONFIGURED' : $key->describe($resolved['value']),
                    'source' => match ($resolved['source']) {
                        'approved' => 'approved version v'.$resolved['version']->version, 'shipped_default' => 'shipped default (config/peopleos.php)', default => 'not configured',
                    },
                    'versions' => $scope === '' && $key->scoped() ? [] : $configuration->history($key, $scope)
                        ->map(fn (ConfigurationVersion $v) => ['version' => $v, 'state' => $configuration->state($v)])->all()];
            }
        }

        return $rows;
    }

    /** @return Collection<int, AuditEvent> */
    public function trail(): Collection
    {
        return app(BillingDirectory::class)->configurationTrail(60);
    }

    public function display(ConfigurationVersion $version): string
    {
        $key = $version->configurationKey();

        return $key === null ? json_encode($version->typedValue()) : $key->describe($version->typedValue());
    }

    public static function stateColor(string $state): string
    {
        return match ($state) {
            'CURRENT' => 'success', 'SCHEDULED', 'PENDING_APPROVAL' => 'warning', 'REJECTED', 'EXPIRED' => 'danger', default => 'gray',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('propose')->label('Propose change')->icon(Heroicon::OutlinedPencilSquare)
                ->modalDescription('A new version from a date (today or later). It applies only when another operator approves it on the Approvals page; earlier invoices keep what applied to them.')
                ->schema([
                    Select::make('key')->label('Setting')->required()->live()
                        ->options(collect(ConfigurationKey::cases())->mapWithKeys(fn (ConfigurationKey $k) => [$k->value => ($k->domain() === ConfigurationKey::STATUTORY ? 'Statutory: ' : 'Policy: ').$k->label()])->all()),
                    TextInput::make('scope')->label('Country (ISO code; statutory values only)')->length(2)
                        ->visible(fn (Get $get) => ConfigurationKey::tryFrom((string) $get('key'))?->scoped() ?? false),
                    Textarea::make('value')->label('New value')->required()->rows(2)
                        ->helperText(fn (Get $get) => match (ConfigurationKey::tryFrom((string) $get('key'))) {
                            ConfigurationKey::B2bOnly, ConfigurationKey::PricesIncludeTax => 'true or false',
                            ConfigurationKey::ProrationRounding => 'half_up or half_even',
                            ConfigurationKey::TdsJurisdictions => 'COUNTRY:CURRENCY, comma-separated, e.g. IN:INR',
                            default => 'A whole number',
                        }),
                    DatePicker::make('from')->label('Effective from')->native(false)->required()->default(now()->toDateString()),
                    DatePicker::make('to')->label('Effective to (optional)')->native(false),
                    TextInput::make('source')->label('Source (authority; required for a statutory value)')->maxLength(150),
                    TextInput::make('source_reference')->label('Legal reference')->maxLength(500),
                    TextInput::make('source_url')->label('Source URL')->url()->maxLength(500),
                    Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500),
                ])
                ->action(fn (array $data) => $this->attempt(function () use ($data) {
                    $key = ConfigurationKey::from($data['key']);
                    $raw = trim((string) $data['value']);
                    $value = $key === ConfigurationKey::TdsJurisdictions && str_starts_with($raw, '[') ? (json_decode($raw, true) ?? throw new RuntimeException('The list is not valid JSON.')) : $raw;
                    app(CommercialConfiguration::class)->propose($key, (string) ($data['scope'] ?? ''), $value, substr((string) $data['from'], 0, 10),
                        blank($data['to'] ?? null) ? null : substr((string) $data['to'], 0, 10), $data['reason'], auth()->user(),
                        ['source' => $data['source'] ?? null, 'source_reference' => $data['source_reference'] ?? null, 'source_url' => $data['source_url'] ?? null]);
                }, 'Change proposed: another operator approves it on the Approvals page')),
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
        }
    }
}
