<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditVerifications;
use App\Domain\Audit\Services\PlatformAuditTrail;
use App\Domain\Platform\Models\Tenant;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * SaaS.8 (WS1 G-PLAT-1, closed here): the platform audit chain for platform operators. What operators did, to which
 * tenant, who, when, why, and the before and after values; the chain's latest verification. Read-only; the platform
 * chain only (a tenant's own trail is in that tenant, reached through audited tenant entry).
 */
class PlatformAuditTrailPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Platform audit trail';

    protected static ?string $title = 'Platform audit trail';

    protected static ?string $slug = 'platform-audit';

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.platform-audit-trail';

    #[Url]
    public ?string $action = null;

    #[Url]
    public ?int $subject = null;

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public ?string $event = null;

    public int $limit = 50;

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return Collection<int, AuditEvent> */
    public function events(): Collection
    {
        $date = fn (?string $d) => $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : null;

        return app(PlatformAuditTrail::class)->events($this->action ?: null, $this->subject ?: null, $date($this->from), $date($this->to), $this->limit);
    }

    public function selectedEvent(): ?AuditEvent
    {
        return $this->event === null ? null : app(PlatformAuditTrail::class)->find($this->event);
    }

    public function actionLabel(AuditEvent $event): string
    {
        $action = $event->action;

        return $action instanceof AuditAction ? $action->label() : (string) $action;
    }

    /** @return array<string, string> */
    public function actionOptions(): array
    {
        return app(PlatformAuditTrail::class)->actions();
    }

    /** @return array<int, string> */
    public function tenantOptions(): array
    {
        return Tenant::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, mixed> */
    public function chainStatus(): array
    {
        return app(AuditVerifications::class)->status();
    }

    public function showMore(): void
    {
        $this->limit = min(500, $this->limit + 50);
    }

    public function resetFilters(): void
    {
        $this->action = $this->subject = $this->from = $this->to = $this->event = null;
        $this->limit = 50;
    }
}
