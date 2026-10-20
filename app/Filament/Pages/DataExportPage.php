<?php

namespace App\Filament\Pages;

use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Services\TenantExports;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

/**
 * SaaS.9: the organisation's own full data export, for people holding `data.export` (the tenant super administrator by
 * default). Only this organisation's exports are listed (tenant scope); every request, download and passphrase reveal is
 * checked in TenantExports and audited. No platform control (closing, holds, deletion) is reachable from here.
 */
class DataExportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Data export';

    protected static ?string $title = 'Export your organisation\'s data';

    protected static ?string $slug = 'data-export';

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.pages.data-export';

    public ?string $revealed = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ! $user->isPlatformAdmin() && $user->can('data.export');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return Collection<int, TenantExport> this organisation's exports only (tenant scope) */
    public function exports(): Collection
    {
        return TenantExport::query()->select(TenantExport::SUMMARY_COLUMNS)->orderByDesc('id')->limit(20)->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestExport')->label('Request a full export')->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->modalDescription('PeopleOS prepares an encrypted package of your organisation\'s data in the background (it can take a while for large organisations). You download it here and open it with the passphrase shown to you. Credentials and secrets are never included.')
                ->schema([Textarea::make('reason')->label('Why do you need it?')->required()->minLength(5)->maxLength(1000)])
                ->action(fn (array $data) => $this->attempt(fn () => app(TenantExports::class)->request(app(TenantContext::class)->current(), auth()->user(), $data['reason']), 'Export requested: it appears below when ready')),
        ];
    }

    public function downloadAction(): Action
    {
        return Action::make('download')->label('Download')->action(function (array $arguments) {
            try {
                return redirect()->away(app(TenantExports::class)->downloadUrl($this->own((int) ($arguments['export'] ?? 0)), auth()->user()));
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }
        });
    }

    public function passphraseAction(): Action
    {
        return Action::make('passphrase')->label('Show passphrase')->requiresConfirmation()
            ->modalDescription('The passphrase opens the encrypted package. Showing it is recorded.')
            ->action(fn (array $arguments) => $this->attempt(function () use ($arguments) {
                $this->revealed = app(TenantExports::class)->revealPassphrase($this->own((int) ($arguments['export'] ?? 0)), auth()->user());
            }, 'Passphrase shown below'));
    }

    /** An export of this organisation only (the tenant scope refuses any other). */
    private function own(int $id): TenantExport
    {
        return TenantExport::query()->find($id) ?? throw new RuntimeException('Export not found.');
    }

    private function attempt(Closure $work, string $success): void
    {
        try {
            $work();
            Notification::make()->success()->title($success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
