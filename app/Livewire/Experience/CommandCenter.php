<?php

namespace App\Livewire\Experience;

use App\Domain\Experience\Services\CommandSearch;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\UxMetrics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * UX: the Command Center (⌘K / Ctrl+K, §10–11). Opening, closing and keyboard movement happen in the
 * browser (instant); only the query goes to the server, where CommandSearch applies the viewer's
 * permissions to every group. Selections are remembered as recent items for the viewer alone.
 */
class CommandCenter extends Component
{
    public string $query = '';

    public string $mode = 'all';

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, ['all', 'actions', 'people'], true) ? $mode : 'all';
        unset($this->groups);
    }

    public function opened(string $mode = 'all'): void
    {
        $this->setMode($mode);
        app(UxMetrics::class)->record('command.open');
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function groups(): array
    {
        $start = hrtime(true);
        $groups = app(CommandSearch::class)->search(auth()->user(), $this->query, $this->mode);
        if (trim($this->query) !== '') {
            $metrics = app(UxMetrics::class);
            $metrics->record('command.search', (int) ((hrtime(true) - $start) / 1_000_000));
            if ($groups === []) {
                $metrics->record('search.no_results');
            } elseif ($groups[0]['key'] === 'answers') {
                $metrics->record('search.intent');
            }
        }

        return $groups;
    }

    /** Remember a chosen result (re-resolved from this viewer's own results, never trusted from the browser). */
    public function remember(string $id): void
    {
        foreach ($this->groups as $group) {
            foreach ($group['items'] as $item) {
                if ($item['id'] !== $id) {
                    continue;
                }
                app(UxMetrics::class)->record('command.select');
                if (in_array($item['type'], ['action', 'answer'], true) || empty($item['url'])) {
                    return;
                }
                app(ExperiencePreferences::class)->pushRecent(auth()->user(), [
                    'type' => $item['type'], 'id' => $item['type'] === 'person' ? $item['person_id'] : $item['id'],
                    'label' => $item['title'], 'url' => $item['url'], 'meta' => $item['subtitle'] ?? null,
                ]);

                return;
            }
        }
    }

    public function render(): View
    {
        return view('livewire.experience.command-center');
    }
}
