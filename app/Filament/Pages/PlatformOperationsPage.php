<?php

namespace App\Filament\Pages;

use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\PlatformOperationsOverview;
use App\Domain\Platform\Services\TenantDeferredWork;
use App\Filament\Resources\Tenants\TenantResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * SaaS.8 (WS8 core): tenant operations for platform operators: per tenant, the status, work deferred during a suspension,
 * notifications that failed or are stuck, failing webhooks, dead letters, a first administrator still without an
 * invitation, and the latest audit-chain verification; platform-wide, runtime health and failed jobs by job class.
 * Counts and states only. The one action here (release deferred work) is operator-only, reasoned and audited in its
 * service; suspend, reactivate and invitations stay on the Tenants list.
 */
class PlatformOperationsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Operations';

    protected static ?string $title = 'Platform operations';

    protected static ?string $slug = 'platform-operations';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.platform-operations';

    #[Url]
    public ?int $tenant = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return list<array<string, mixed>> */
    public function tenantRows(): array
    {
        return $this->rows ??= app(PlatformOperationsOverview::class)->tenants();
    }

    /** @return array<string, mixed> */
    public function platform(): array
    {
        return app(PlatformOperationsOverview::class)->platform();
    }

    /** @return array<string, mixed>|null */
    public function selected(): ?array
    {
        return collect($this->tenantRows())->first(fn (array $row) => $row['tenant']->id === $this->tenant);
    }

    public function tenantUrl(Tenant $tenant): string
    {
        return TenantResource::getUrl('edit', ['record' => $tenant]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('releaseDeferred')
                ->label('Release deferred work')
                ->icon(Heroicon::OutlinedPlayCircle)
                ->visible(fn () => ($row = $this->selected()) !== null && $row['deferred'] > 0 && $row['tenant']->isAccessible())
                ->modalDescription(fn () => "Dispatches again the {$this->selected()['deferred']} job(s) queued for {$this->selected()['tenant']->name} while it was suspended. Each runs once.")
                ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
                ->action(function (array $data, TenantDeferredWork $work) {
                    try {
                        $released = $work->release($this->selected()['tenant'], auth()->user(), $data['reason']);
                        Notification::make()->success()->title("{$released} job(s) dispatched again")->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                    $this->rows = null;
                }),
        ];
    }
}
