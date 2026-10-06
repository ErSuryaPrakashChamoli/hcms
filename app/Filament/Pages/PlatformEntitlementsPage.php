<?php

namespace App\Filament\Pages;

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\CapabilityType;
use App\Domain\Entitlements\Models\EntitlementOverride;
use App\Domain\Entitlements\Models\TenantEntitlement;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\EntitlementDiagnostics;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * SaaS.3: commercial entitlements for platform operators only: the capability catalogue, a tenant's decisions on
 * a chosen business date with the layers behind each one, its configuration and override history, and the shadow
 * observations ("what would have been denied"). Every change goes through EntitlementConfiguration (operator-only,
 * reasoned, audited). Nothing here is shown to tenant users, and no HCM navigation depends on it (shadow mode).
 */
class PlatformEntitlementsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Entitlements';

    protected static ?string $title = 'Commercial entitlements (shadow mode)';

    protected static ?string $slug = 'platform-entitlements';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.platform-entitlements';

    #[Url]
    public ?int $tenant = null;

    #[Url]
    public ?string $day = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->day ??= now()->toDateString();
    }

    public function selectedTenant(): ?Tenant
    {
        return $this->tenant ? Tenant::query()->find($this->tenant) : null;
    }

    /** @return array<int, string> */
    public function tenantOptions(): array
    {
        return Tenant::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function catalog(): Collection
    {
        $tenant = $this->selectedTenant();

        return $tenant ? app(EntitlementDiagnostics::class)->catalog($tenant, $this->day) : collect();
    }

    /** @return array{configuration: Collection, overrides: Collection} */
    public function history(): array
    {
        $tenant = $this->selectedTenant();
        if ($tenant === null) {
            return ['configuration' => collect(), 'overrides' => collect()];
        }

        return app(TenantContext::class)->runAs($tenant, fn () => [
            'configuration' => TenantEntitlement::query()->orderByDesc('id')->limit(200)->get(),
            'overrides' => EntitlementOverride::query()->orderByDesc('id')->limit(200)->get(),
        ]);
    }

    public function shadow(): Collection
    {
        return app(EntitlementDiagnostics::class)->shadowSummary(7, $this->tenant, bySurface: true);
    }

    protected function getHeaderActions(): array
    {
        $commercial = collect(Capability::commercialCases())->mapWithKeys(fn (Capability $c) => [$c->value => "{$c->value} · {$c->label()}"])->all();
        $reason = fn () => Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500);
        $value = fn () => [
            Toggle::make('enabled')->label('Available')->visible(fn (Get $get) => ($c = Capability::tryFrom((string) $get('capability'))) && $c->type() !== CapabilityType::Limit),
            Toggle::make('unlimited')->label('Unlimited')->live()->visible(fn (Get $get) => Capability::tryFrom((string) $get('capability'))?->type() === CapabilityType::Limit),
            TextInput::make('limit')->numeric()->minValue(0)->label('Limit')->visible(fn (Get $get) => Capability::tryFrom((string) $get('capability'))?->type() === CapabilityType::Limit && ! $get('unlimited')),
        ];
        $tenantChosen = fn () => $this->selectedTenant() !== null;

        return [
            Action::make('configure')->label('Start configuration')->icon(Heroicon::OutlinedPlay)->visible($tenantChosen)
                ->modalDescription('From this business date an absent capability is NOT entitled (DENY) instead of UNKNOWN. Shadow mode: nothing is blocked.')
                ->schema([DatePicker::make('from')->native(false)->required()->default(now()->toDateString()), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(EntitlementConfiguration::class)->configure($this->selectedTenant(), $this->date($data['from']), $data['reason'], auth()->user()), 'Configuration start recorded')),
            Action::make('set')->label('Set entitlement')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->visible($tenantChosen)
                ->schema([Select::make('capability')->options($commercial)->required()->live(), ...$value(),
                    DatePicker::make('from')->native(false)->required()->default(now()->toDateString()), DatePicker::make('to')->native(false)->label('Last day (optional)'),
                    TextInput::make('reference')->maxLength(100), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(EntitlementConfiguration::class)->set($this->selectedTenant(), Capability::from($data['capability']), $this->valueFrom($data),
                    $this->date($data['from']), $this->date($data['to'] ?? null), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Entitlement set')),
            Action::make('grantOverride')->label('Grant override')->icon(Heroicon::OutlinedSparkles)->visible($tenantChosen)
                ->modalDescription('An explicit exception that wins over the configuration for its period. One override per capability at a time.')
                ->schema([Select::make('capability')->options($commercial)->required()->live(), ...$value(),
                    DatePicker::make('from')->native(false)->required()->default(now()->toDateString()), DatePicker::make('to')->native(false)->label('Last day (optional)'),
                    TextInput::make('reference')->label('Ticket or contract reference')->maxLength(100), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(EntitlementConfiguration::class)->grantOverride($this->selectedTenant(), Capability::from($data['capability']), $this->valueFrom($data),
                    $this->date($data['from']), $this->date($data['to'] ?? null), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Override granted')),
            Action::make('endEntitlement')->label('End entitlement')->icon(Heroicon::OutlinedStop)->color('gray')->visible($tenantChosen)
                ->schema([Select::make('row')->label('Configuration row')->required()->options(fn () => $this->history()['configuration']->where('status', 'active')
                    ->mapWithKeys(fn (TenantEntitlement $r) => [$r->id => "#{$r->id} {$r->capability->value} from {$r->effective_from->toDateString()}"])->all()),
                    DatePicker::make('last_day')->native(false)->required()->default(now()->subDay()->toDateString()), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(EntitlementConfiguration::class)->end($this->row(TenantEntitlement::class, (int) $data['row']), $this->date($data['last_day']), $data['reason'], auth()->user()), 'Entitlement ended')),
            Action::make('revokeOverride')->label('Revoke override')->icon(Heroicon::OutlinedXMark)->color('danger')->visible($tenantChosen)
                ->schema([Select::make('override')->required()->options(fn () => $this->history()['overrides']->where('status', 'active')
                    ->mapWithKeys(fn (EntitlementOverride $o) => [$o->id => "#{$o->id} {$o->capability->value} from {$o->effective_from->toDateString()}"])->all()), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(EntitlementConfiguration::class)->revokeOverride($this->row(EntitlementOverride::class, (int) $data['override']), $data['reason'], auth()->user()), 'Override revoked')),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function valueFrom(array $data): bool|int|null
    {
        $capability = Capability::from($data['capability']);
        if ($capability->type() !== CapabilityType::Limit) {
            return (bool) ($data['enabled'] ?? false);
        }

        return ($data['unlimited'] ?? false) ? null : (int) ($data['limit'] ?? 0);
    }

    private function date(mixed $value): ?string
    {
        return blank($value) ? null : substr((string) $value, 0, 10);
    }

    /** @param  class-string<TenantEntitlement|EntitlementOverride>  $model */
    private function row(string $model, int $id): TenantEntitlement|EntitlementOverride
    {
        return app(TenantContext::class)->runAs($this->selectedTenant(), fn () => $model::query()->findOrFail($id));
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
