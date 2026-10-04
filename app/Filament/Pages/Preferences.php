<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\PeopleVisibility;
use App\Domain\Experience\Services\RoleLens;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * UX: personalisation (§31–32). Density, the default Home lens, hidden Home cards and pinned people.
 * Theme (light / dark / system) is the panel's own switcher in the avatar menu; notification channels
 * stay in My HR → Preferences (the Communication domain owns them).
 */
class Preferences extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'Preferences';

    protected static ?string $title = 'Preferences';

    protected static ?string $slug = 'preferences';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.experience.preferences';

    public static function canAccess(): bool
    {
        return auth()->check() && app(TenantContext::class)->has();
    }

    public function getSubheading(): ?string
    {
        return 'How PeopleOS looks and opens for you. These settings are yours alone.';
    }

    #[Computed]
    public function prefs(): array
    {
        return app(ExperiencePreferences::class)->for(auth()->user());
    }

    /** UX.16: the experiences this person holds (lens that opens each => label), with the same labels as Home. */
    #[Computed]
    public function lenses(): array
    {
        return collect(app(RoleLens::class)->experiences(auth()->user()))
            ->mapWithKeys(fn (string $lens, string $experience) => [$lens => RoleLens::EXPERIENCE_LABELS[$experience] ?? $experience])->all();
    }

    #[Computed]
    public function pinned()
    {
        $ids = $this->prefs['pinned_people'] ?? [];

        return $ids === [] ? collect() : app(PeopleVisibility::class)->query(auth()->user())->with('person')->whereKey($ids)->get();
    }

    public function setDensity(string $density): void
    {
        $this->save(['density' => $density], 'Density updated');
        $this->js('document.documentElement.dataset.posDensity = '.json_encode($density === 'compact' ? 'compact' : 'comfortable'));
    }

    public function setLens(?string $lens): void
    {
        if ($lens && ! app(RoleLens::class)->has(auth()->user(), $lens)) {
            return;
        }
        $this->save(['lens' => $lens ?: null], 'Home lens updated');
        // UX.17: the phone bar follows the new view at once.
        $this->dispatch('pos-experience-changed');
    }

    public function restoreCards(): void
    {
        $this->save(['home_hidden' => []], 'Home cards restored');
    }

    public function unpin(int $id): void
    {
        app(ExperiencePreferences::class)->togglePin(auth()->user(), $id);
        unset($this->prefs, $this->pinned);
    }

    public function clearRecent(): void
    {
        $this->save(['recent' => []], 'Recent items cleared');
    }

    private function save(array $changes, string $message): void
    {
        app(ExperiencePreferences::class)->update(auth()->user(), $changes);
        unset($this->prefs, $this->pinned);
        Notification::make()->success()->title($message)->send();
    }
}
