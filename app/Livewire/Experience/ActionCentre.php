<?php

namespace App\Livewire\Experience;

use App\Domain\Experience\Services\ActionCatalogue;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\UxMetrics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * HROne parity AC-1: the Actions panel (HROne's HR Desk "Actions"). Every HR action the signed-in person may run, grouped
 * by module, with a search box, the actions they started last and the ones they pinned. Opened from the top bar (and the
 * A shortcut) as a full-screen panel, or shown inline on the Actions page. Filtering happens in the browser (instant); the
 * list itself, pins and "run" are re-resolved on the server from ActionCatalogue, so a key from the browser or from
 * preferences never opens anything the person could not open from the module itself.
 */
class ActionCentre extends Component
{
    /** Inline on the Actions page; otherwise the full-screen panel opened from the top bar. */
    public bool $inline = false;

    /** The catalogue is built once the panel has been opened (it starts closed on every page). */
    public bool $active = false;

    public function mount(bool $inline = false): void
    {
        $this->inline = $inline;
        $this->active = $inline;
    }

    public function opened(): void
    {
        $this->active = true;
    }

    /** @return list<array{key: string, label: string, icon: string, items: list<array<string, mixed>>}> */
    #[Computed]
    public function sections(): array
    {
        $user = auth()->user();

        return $user === null || ! $this->active ? [] : app(ActionCatalogue::class)->sections($user);
    }

    /** @return list<array<string, mixed>> the person's pinned actions they may still run, newest pin first */
    #[Computed]
    public function pinned(): array
    {
        return $this->remembered('pinned_actions');
    }

    /** @return list<array<string, mixed>> the actions the person started last, newest first */
    #[Computed]
    public function recent(): array
    {
        return $this->remembered('recent_actions');
    }

    /** @return list<string> */
    #[Computed]
    public function pinnedKeys(): array
    {
        return array_column($this->pinned, 'key');
    }

    /** Start an action: remember it as recent, then open its screen (or its form on that screen). */
    public function run(string $key): mixed
    {
        $user = auth()->user();
        $action = $user === null ? null : app(ActionCatalogue::class)->find($user, $key);
        if ($action === null) {
            unset($this->sections, $this->pinned, $this->recent, $this->pinnedKeys);

            return null;
        }
        app(ExperiencePreferences::class)->pushRecentAction($user, $key);
        app(UxMetrics::class)->record('action.started');

        return $this->redirect($action['url'], navigate: true);
    }

    public function togglePin(string $key): void
    {
        $user = auth()->user();
        if ($user !== null && app(ActionCatalogue::class)->find($user, $key) !== null) {
            app(ExperiencePreferences::class)->togglePinnedAction($user, $key);
        }
        unset($this->pinned, $this->pinnedKeys);
    }

    public function render(): View
    {
        return view('livewire.experience.action-centre');
    }

    /** @return list<array<string, mixed>> */
    private function remembered(string $preference): array
    {
        $user = auth()->user();
        if ($user === null || ! $this->active) {
            return [];
        }
        $catalogue = app(ActionCatalogue::class);
        $rows = [];
        foreach (app(ExperiencePreferences::class)->for($user)[$preference] ?? [] as $key) {
            if (is_string($key) && ($action = $catalogue->find($user, $key)) !== null) {
                $rows[] = $action;
            }
        }

        return $rows;
    }
}
