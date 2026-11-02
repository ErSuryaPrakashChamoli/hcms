<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Services\ActionCatalogue;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * HROne parity AC-1: the Actions panel as a page (HROne's HR Desk "Actions"): every HR action the person may run, by
 * module, with search, recent and pinned actions. The same Livewire panel opens full-screen from the top bar; this page
 * is its link for the Command Center, bookmarks and phones. Nothing here runs anything: each action opens its module's
 * own screen or form (ActionCatalogue).
 */
class ActionsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Actions';

    protected static ?string $slug = 'actions';

    protected string $view = 'filament.pages.experience.actions';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TenantContext::class)->has() && app(ActionCatalogue::class)->hasAny($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Every HR action you can run, by module. Pin the ones you use most.';
    }
}
