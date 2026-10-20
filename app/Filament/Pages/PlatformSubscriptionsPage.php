<?php

namespace App\Filament\Pages;

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Entitlements\Models\TenantPlanAssignment;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Enums\CommercialStatus;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Domain\Subscriptions\Services\SubscriptionDirectory;
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
 * SaaS.6: commercial subscriptions and trials for platform operators only: every tenant's technical and commercial
 * state today, and for one tenant its subscription timeline (voided periods included), the plan assignments the
 * subscription projects, and Markedge's audit trail of every change. Every change goes through
 * CommercialSubscriptions (operator-only, reasoned, effective-dated, audited); only legal changes are offered.
 * No price, payment or invoice exists here, and nothing is enforced (shadow mode).
 */
class PlatformSubscriptionsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Subscriptions';

    protected static ?string $title = 'Commercial subscriptions (shadow mode)';

    protected static ?string $slug = 'platform-subscriptions';

    protected static ?int $navigationSort = 22;

    protected string $view = 'filament.pages.platform-subscriptions';

    #[Url]
    public ?int $tenant = null;

    /** Read once per request (non-public properties do not survive Livewire requests). */
    private ?Collection $loaded = null;

    /** @var array<int, Tenant|null> */
    private array $tenants = [];

    /** @var array<int, string> */
    private array $labels = [];

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

    /** @return list<array{tenant: Tenant, state: ?array, subscription_id: ?int, version: ?string}> */
    public function overview(): array
    {
        return app(SubscriptionDirectory::class)->overview($this->today());
    }

    public function selectedTenant(): ?Tenant
    {
        return $this->tenant ? ($this->tenants[$this->tenant] ??= Tenant::query()->find($this->tenant)) : null;
    }

    /** @return Collection<int, TenantSubscription> newest first, with their periods and plan versions */
    public function subscriptions(): Collection
    {
        $tenant = $this->selectedTenant();
        if ($tenant === null) {
            return collect();
        }

        return $this->loaded ??= app(TenantContext::class)->runAs($tenant, fn () => TenantSubscription::query()
            ->with(['periods.planVersion.plan'])->orderByDesc('id')->get());
    }

    /** The subscription that is not cancelled on or before today, if any. */
    public function live(): ?TenantSubscription
    {
        return $this->subscriptions()->first(fn (TenantSubscription $s) => ($c = $s->timeline()->cancelledFrom()) === null || $c > $this->today());
    }

    /** @return array{status: CommercialStatus, plan_version_id: int, from: string, to: ?string, derived: bool}|null */
    public function stateToday(): ?array
    {
        return $this->live()?->timeline()->stateOn($this->today());
    }

    public function versionLabel(?int $id): string
    {
        return $id === null ? '—' : ($this->labels[$id] ??= PlanVersion::query()->with('plan')->find($id)?->label() ?? "#{$id}");
    }

    /** @return Collection<int, TenantPlanAssignment> the assignments subscriptions projected, newest first */
    public function assignments(): Collection
    {
        $tenant = $this->selectedTenant();

        return $tenant ? app(TenantContext::class)->runAs($tenant, fn () => TenantPlanAssignment::query()->with('planVersion.plan')
            ->whereNotNull('subscription_id')->orderByDesc('id')->limit(100)->get()) : collect();
    }

    public function auditTrail(): Collection
    {
        return $this->tenant ? app(SubscriptionDirectory::class)->auditTrail($this->tenant) : collect();
    }

    protected function getHeaderActions(): array
    {
        $reason = fn () => Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500);
        $reference = fn () => TextInput::make('reference')->label('Contract, order or ticket reference')->maxLength(100);
        $from = fn (string $label = 'Effective from') => DatePicker::make('from')->label($label)->native(false)->required()->default(now()->toDateString());
        $until = fn (bool $required, string $label = 'Last day') => DatePicker::make('until')->label($label.($required ? '' : ' (optional: open-ended)'))->native(false)->required($required);
        $version = fn () => Select::make('version')->label('Plan version')->required()->options(fn () => $this->onSale());
        $is = fn (CommercialStatus ...$states) => fn () => ($s = $this->stateToday()) !== null && in_array($s['status'], $states, true) && ! $s['derived'];
        $noLive = fn () => $this->selectedTenant() !== null && $this->live() === null;
        $now = fn () => ($s = $this->stateToday()) === null ? 'none' : $s['status']->value.($s['derived'] ? ' (end passed)' : '').' · '.$this->versionLabel($s['plan_version_id']);
        $live = fn () => $this->live() ?? throw new RuntimeException('This tenant has no live subscription.');
        $service = fn () => app(CommercialSubscriptions::class);

        return [
            Action::make('startTrial')->label('Start trial')->icon(Heroicon::OutlinedBeaker)->visible($noLive)
                ->modalDescription('none → trial. The trial uses the chosen published version; its end date is explicit. Nothing is converted or charged automatically.')
                ->schema([$version(), $from('Trial starts'), $until(true, 'Trial ends'), $reference(), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->startTrial($this->selectedTenant(), PlanVersion::query()->findOrFail((int) $data['version']),
                    $this->date($data['from']), $this->date($data['until']), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Trial started')),
            Action::make('startSubscription')->label('Start subscription')->icon(Heroicon::OutlinedPlay)->visible($noLive)
                ->modalDescription('none → active on the chosen published version.')
                ->schema([$version(), $from(), $until(false), $reference(), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->start($this->selectedTenant(), PlanVersion::query()->findOrFail((int) $data['version']),
                    $this->date($data['from']), $this->date($data['until'] ?? null), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Subscription started')),
            Action::make('convert')->label('Convert trial')->icon(Heroicon::OutlinedCheckCircle)->color('success')->visible($is(CommercialStatus::Trial))
                ->modalDescription(fn () => "Now: {$now()} → active from the chosen date (same plan version).")
                ->schema([$from(), $until(false), $reference(), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->convert($live(), $this->date($data['from']), $this->date($data['until'] ?? null), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Trial converted')),
            Action::make('extend')->label('Extend')->icon(Heroicon::OutlinedArrowRightCircle)->visible($is(CommercialStatus::Trial, CommercialStatus::Active, CommercialStatus::Grace))
                ->modalDescription(fn () => "Now: {$now()} → the same state continues after its current end, until the new last day.")
                // A trial or grace needs an explicit new end; an active term may become open-ended.
                ->schema(fn () => [$until(($this->stateToday()['status'] ?? null) !== CommercialStatus::Active, 'New last day'), $reference(), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->extend($live(), $this->date($data['until'] ?? null), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Extended')),
            Action::make('enterGrace')->label('Enter grace')->icon(Heroicon::OutlinedClock)->color('warning')->visible($is(CommercialStatus::Active))
                ->modalDescription(fn () => "Now: {$now()} → grace from the chosen date until an explicit last day (still entitled). Nothing enters grace automatically.")
                ->schema([$from(), $until(true, 'Grace ends'), $reference(), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->enterGrace($live(), $this->date($data['from']), $this->date($data['until']), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Grace entered')),
            Action::make('reactivate')->label('Reactivate')->icon(Heroicon::OutlinedArrowPath)->color('success')
                ->visible(fn () => ($s = $this->stateToday()) !== null && in_array($s['status'], [CommercialStatus::Grace, CommercialStatus::Expired], true))
                ->modalDescription(fn () => "Now: {$now()} → active from the chosen date (same plan version).")
                ->schema([$from(), $until(false), $reference(), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->reactivate($live(), $this->date($data['from']), $this->date($data['until'] ?? null), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Reactivated')),
            Action::make('changePlan')->label('Change plan version')->icon(Heroicon::OutlinedArrowsRightLeft)->visible($is(CommercialStatus::Trial, CommercialStatus::Active, CommercialStatus::Grace))
                ->modalDescription(fn () => "Now: {$now()} → the chosen version from the chosen date. States and dates stay as they are; tenants never move to a new version on their own.")
                ->schema([$version(), $from(), $reference(), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->changePlan($live(), PlanVersion::query()->findOrFail((int) $data['version']), $this->date($data['from']), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Plan version changed')),
            Action::make('expire')->label('Expire')->icon(Heroicon::OutlinedStop)->color('gray')->visible($is(CommercialStatus::Trial, CommercialStatus::Active, CommercialStatus::Grace))
                ->modalDescription(fn () => "Now: {$now()} → expired from the chosen date (the plan stops being in force; nothing is blocked).")
                ->schema([$from(), $reference(), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->expire($live(), $this->date($data['from']), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Expired')),
            Action::make('cancel')->label('Cancel subscription')->icon(Heroicon::OutlinedXCircle)->color('danger')->visible(fn () => $this->live() !== null)
                ->modalDescription(fn () => "Now: {$now()} → cancelled from the chosen date. Cancellation is final for this subscription; a returning customer gets a new one.")
                ->schema([$from('Cancelled from'), $reference(), $reason()])
                ->action(fn (array $data) => $this->attempt(fn () => $service()->cancel($live(), $this->date($data['from']), $data['reason'], auth()->user(), $data['reference'] ?? null), 'Subscription cancelled')),
        ];
    }

    /** @return array<int, string> published versions on sale today */
    private function onSale(): array
    {
        return PlanVersion::query()->with('plan')->where('status', VersionStatus::Published)->orderBy('plan_id')->orderByDesc('version')->get()
            ->filter(fn (PlanVersion $v) => $v->onSaleOn($this->today()))
            ->mapWithKeys(fn (PlanVersion $v) => [$v->id => "{$v->label()} · {$v->plan->name}"])->all();
    }

    private function date(mixed $value): ?string
    {
        return blank($value) ? null : substr((string) $value, 0, 10);
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
            $this->loaded = null;
        }
    }
}
