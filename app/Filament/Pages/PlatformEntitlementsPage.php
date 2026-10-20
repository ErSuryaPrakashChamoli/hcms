<?php

namespace App\Filament\Pages;

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\CapabilityType;
use App\Domain\Entitlements\Models\EntitlementOverride;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Entitlements\Models\TenantEntitlement;
use App\Domain\Entitlements\Models\TenantPlanAssignment;
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
 * SaaS.4: the tenant's plan (assign a published version, end an assignment), its assignment history and the
 * platform-chain audit of its commercial configuration. Plans themselves are managed on Platform › Plans.
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

    /** @return array{configuration: Collection, overrides: Collection, assignments: Collection} */
    public function history(): array
    {
        $tenant = $this->selectedTenant();
        if ($tenant === null) {
            return ['configuration' => collect(), 'overrides' => collect(), 'assignments' => collect()];
        }

        return app(TenantContext::class)->runAs($tenant, fn () => [
            'configuration' => TenantEntitlement::query()->orderByDesc('id')->limit(200)->get(),
            'overrides' => EntitlementOverride::query()->orderByDesc('id')->limit(200)->get(),
            'assignments' => TenantPlanAssignment::query()->with('planVersion.plan')->orderByDesc('id')->limit(200)->get(),
        ]);
    }

    /** SaaS.4: Markedge's platform-chain audit of this tenant's commercial configuration. */
    public function auditTrail(): Collection
    {
        return $this->tenant ? app(EntitlementDiagnostics::class)->auditTrail(tenantId: $this->tenant) : collect();
    }

    /** @return array<int, string> published versions, labelled with their sale window */
    private function assignableVersions(): array
    {
        return PlanVersion::query()->with('plan')->where('status', VersionStatus::Published)->orderBy('plan_id')->orderByDesc('version')->get()
            ->mapWithKeys(fn (PlanVersion $v) => [$v->id => "{$v->label()} · {$v->plan->name} · on sale {$v->effective_from->toDateString()} to ".($v->effective_to?->toDateString() ?? 'open')])->all();
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
            Action::make('assignPlan')->label('Assign plan')->icon(Heroicon::OutlinedRectangleStack)->visible($tenantChosen)
                ->modalDescription('The tenant is on this published plan version from the date. Capabilities set for the tenant itself and overrides still win. Shadow mode: nothing is blocked.')
                ->schema([Select::make('version')->label('Plan version')->required()->options(fn () => $this->assignableVersions()),
                    DatePicker::make('from')->native(false)->required()->default(now()->toDateString()), DatePicker::make('to')->native(false)->label('Last day (optional)'),
                    TextInput::make('reference')->label('Contract or deal reference')->maxLength(100), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(EntitlementConfiguration::class)->assignPlan($this->selectedTenant(), PlanVersion::query()->findOrFail((int) $data['version']),
                    $this->date($data['from']), $this->date($data['to'] ?? null), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Plan assigned')),
            Action::make('endPlanAssignment')->label('End plan')->icon(Heroicon::OutlinedStop)->color('gray')->visible($tenantChosen)
                ->schema([Select::make('assignment')->label('Plan assignment')->required()->options(fn () => $this->history()['assignments']->where('status', TenantPlanAssignment::ACTIVE)
                    ->mapWithKeys(fn (TenantPlanAssignment $a) => [$a->id => "#{$a->id} {$a->planVersion->label()} from {$a->effective_from->toDateString()}".($a->effective_to ? " to {$a->effective_to->toDateString()}" : '')])->all()),
                    DatePicker::make('last_day')->native(false)->required()->default(now()->subDay()->toDateString()), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => app(EntitlementConfiguration::class)->endPlanAssignment($this->row(TenantPlanAssignment::class, (int) $data['assignment']),
                    $this->date($data['last_day']), $data['reason'], auth()->user()), 'Plan assignment ended')),
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

    /** @param  class-string<TenantEntitlement|EntitlementOverride|TenantPlanAssignment>  $model */
    private function row(string $model, int $id): TenantEntitlement|EntitlementOverride|TenantPlanAssignment
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
