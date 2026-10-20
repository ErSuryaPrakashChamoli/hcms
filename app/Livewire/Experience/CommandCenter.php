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

    /** UX.18: suggestions are built once the center has been opened, not on every page load (it starts closed). */
    public bool $active = false;

    /** UX.15: a change waiting for its person ("Start a transfer" → choose whom). Re-checked on every use. */
    public ?string $pick = null;

    /** @var array<string, array{label: string, permission: string}> */
    private const PICKS = [
        'assignPosition' => ['label' => 'Transfer or promote', 'permission' => 'employee.position'],
        'changeManager' => ['label' => 'Change manager', 'permission' => 'employee.position'],
    ];

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, ['all', 'actions', 'people'], true) ? $mode : 'all';
        unset($this->groups);
    }

    public function opened(string $mode = 'all'): void
    {
        $this->active = true;
        $this->pick = null;
        $this->setMode($mode);
        app(UxMetrics::class)->record('command.open');
    }

    /** Choose whom a change is for; only changes the viewer may start (the profile action re-checks per person). */
    public function pick(?string $action = null): void
    {
        $this->pick = $action !== null && isset(self::PICKS[$action]) && auth()->user()?->hasPermission(self::PICKS[$action]['permission']) ? $action : null;
        $this->query = '';
        $this->setMode($this->pick !== null ? 'people' : 'all');
    }

    public function pickLabel(): ?string
    {
        return $this->pick !== null ? self::PICKS[$this->pick]['label'] : null;
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function groups(): array
    {
        $start = hrtime(true);
        $groups = app(CommandSearch::class)->search(auth()->user(), $this->query, $this->mode);
        if ($this->pick !== null) {
            // Each person result opens the chosen change on their Employee 360; people whose profile the
            // viewer may not open are not offered (the 360 action would refuse them anyway).
            $groups = array_values(array_filter(array_map(function (array $group) {
                if ($group['key'] !== 'people') {
                    return null;
                }
                $group['items'] = array_values(array_filter(array_map(fn (array $item) => empty($item['url']) ? null
                    : ['url' => strtok($item['url'], '#').'?action='.$this->pick, 'drawer' => null, 'actions' => [], 'verb' => self::PICKS[$this->pick]['label']] + $item, $group['items'])));

                return $group['items'] === [] ? null : $group;
            }, $groups)));
        }
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
