<?php

namespace App\Livewire\Resolution;

use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Resolution\MasterCatalogue;
use App\Domain\Organisation\Resolution\MasterResolver;
use App\Domain\Organisation\Resolution\ReferenceMappings;
use App\Domain\Organisation\Resolution\ResolutionRefusedException;
use App\Domain\Organisation\Resolution\ResolutionReport;
use App\Domain\Organisation\Resolution\ResolutionSession;
use App\Domain\Organisation\Resolution\ResolutionStatus;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The review of an upload's references (docs/architecture/name-resolution.md): "Supplied name | Resolved record | Entity |
 * Status", with what a person may do about each one that did not resolve: take a suggested record, pick a record, or
 * create the missing one. Every action goes through ReferenceMappings, which re-checks the person's rights, their access
 * and the company's applicability; this component only presents. The host page passes the session's report and the
 * upload's scope (both locked: the browser cannot change them) and re-checks the upload on `references-resolved`.
 *
 *     @livewire(\App\Livewire\Resolution\ReferenceReview::class, ['report' => $report, 'scope' => "employee_imports:{$id}"], key("refs-{$id}"))
 */
class ReferenceReview extends Component
{
    /** Picker options shown per entry; a master with more records is settled by its code in the file instead. */
    public const OPTIONS = 300;

    /** Rows shown at a time; "Show more" adds as many again. */
    public const PAGE = 50;

    /** @var array{scope?: ?string, entries?: list<array<string, mixed>>, overflow?: int, hidden?: array<string, int>} */
    #[Locked]
    public array $report = [];

    #[Locked]
    public ?string $scope = null;

    /** Show every reference (matched ones too), not only those that need attention. */
    public bool $all = false;

    /** How many rows of the table are shown (raised by showMore() only). */
    #[Locked]
    public int $shown = self::PAGE;

    /** @var array<string, bool> entry key => keep the mapping for every later upload */
    public array $remember = [];

    /** @var array<string, int|string|null> entry key => record chosen in the picker */
    public array $choice = [];

    private ?ResolutionSession $session = null;

    /** @param  array{scope?: ?string, entries?: list<array<string, mixed>>, overflow?: int, hidden?: array<string, int>}  $report */
    public function mount(array $report = [], ?string $scope = null): void
    {
        // Kept compact: the report travels with every click (a large file may name hundreds of people).
        $this->report = ResolutionReport::fromArray($report)->forReview()->toArray();
        $this->scope = $scope ?? ($this->report['scope'] ?? null);
    }

    /** Take a record offered for the entry (a suggestion, one of several namesakes) or chosen in its picker. */
    public function useRecord(string $key, int|string|null $recordId = null): void
    {
        $entry = $this->entry($key);
        $recordId = (int) ($recordId ?? ($this->choice[$key] ?? 0));
        if ($entry === null || $recordId <= 0) {
            return;
        }
        $value = (string) ($entry['value'] ?? $entry['name'] ?? '');
        try {
            app(ReferenceMappings::class)->map($this->actor(), $entry['master'], $value, $recordId, $entry['company_id'] ?? null,
                $this->scope, (bool) ($this->remember[$key] ?? false));
        } catch (ResolutionRefusedException $e) {
            Notification::make()->danger()->title('Not mapped')->body($e->getMessage())->send();

            return;
        }
        $row = $this->session()->index($entry['master'])->find($recordId);
        $this->settle($key, $recordId, 'mapping', $row === null ? "#{$recordId}" : $this->label($row));
        Notification::make()->success()->title("'{$value}' now means {$this->entry($key)['record']}")
            ->body(($this->remember[$key] ?? false) ? 'Remembered for every later upload.' : 'For this upload.')->send();
    }

    /** Create the missing master the entry names (its own create right; the company's record, or this company only). */
    public function createMissing(string $key): void
    {
        $entry = $this->entry($key);
        if ($entry === null || ! $this->canCreate($entry)) {
            return;
        }
        $name = (string) ($entry['value'] ?? $entry['name'] ?? '');
        try {
            $record = app(ReferenceMappings::class)->create($this->actor(), $entry['master'], $name, $entry['company_id'] ?? null, $entry['code'] ?? null);
        } catch (ResolutionRefusedException $e) {
            Notification::make()->danger()->title('Not created')->body($e->getMessage())->send();

            return;
        }
        $spec = app(MasterCatalogue::class)->for($entry['master']);
        $code = $spec->codeColumn !== null ? $record->getAttribute($spec->codeColumn) : null;
        $this->settle($key, (int) $record->getKey(), 'created', $name.($code ? " ({$code})" : ''));
        Notification::make()->success()->title("{$spec->label} {$name} created")->send();
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        $entries = $this->report['entries'] ?? [];
        if (! $this->all) {
            // What needs attention, plus what was settled here (so the person sees the outcome of what they just did).
            $entries = array_filter($entries, fn (array $e) => ! ResolutionStatus::from($e['status'])->ok() || in_array($e['via'] ?? null, ['mapping', 'created'], true));
        }
        usort($entries, fn (array $a, array $b) => [ResolutionStatus::from($a['status'])->ok(), $a['master_label'], (string) $a['supplied']]
            <=> [ResolutionStatus::from($b['status'])->ok(), $b['master_label'], (string) $b['supplied']]);

        return array_values($entries);
    }

    public function showMore(): void
    {
        $this->shown += self::PAGE;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $hidden = $this->report['hidden'] ?? [];
        $counts = ['matched' => (int) ($hidden['matched_count'] ?? 0), 'attention' => (int) ($hidden['attention_count'] ?? 0), 'settled' => 0];
        foreach ($this->report['entries'] ?? [] as $e) {
            $ok = ResolutionStatus::from($e['status'])->ok();
            $counts[$ok ? 'matched' : 'attention'] += (int) $e['count'];
            $counts['settled'] += in_array($e['via'] ?? null, ['mapping', 'created'], true) ? 1 : 0;
        }

        return $counts;
    }

    /**
     * Records the person may pick for the entry: those of its master they can see that apply to its company, live first.
     *
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    public function options(array $entry): array
    {
        if (! ResolutionStatus::from($entry['status'])->reviewable()) {
            return [];
        }
        $index = $this->session()->index($entry['master']);
        $rows = [];
        foreach ($index->suggestible() as $row) {
            if (($entry['company_id'] ?? null) !== null && ! $index->appliesTo($row['id'], (int) $entry['company_id'])) {
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, fn (array $a, array $b) => [! $a['live'], mb_strtolower($a['name'])] <=> [! $b['live'], mb_strtolower($b['name'])]);

        return array_column(array_map(fn (array $r) => ['id' => $r['id'], 'label' => $this->label($r).($r['live'] ? '' : ' · inactive')], array_slice($rows, 0, self::OPTIONS)), 'label', 'id');
    }

    /** @param  array<string, mixed>  $entry */
    public function canCreate(array $entry): bool
    {
        $catalogue = app(MasterCatalogue::class);
        $spec = $catalogue->for($entry['master']);

        return $spec->creatable
            && in_array($entry['status'], [ResolutionStatus::Unmatched->value, ResolutionStatus::Suggested->value], true)
            && ($spec->ownerColumn === null || ($entry['company_id'] ?? null) !== null)
            && $catalogue->mayCreate($this->actor(), $spec, $entry['company_id'] ?? null);
    }

    /**
     * Names first (2026-11-26): where a value that is not on file can be added when the review cannot add it itself — a
     * list that carries rules (a leave type, a shift, a salary structure, a pay item …) is set up on its own screen, then
     * the file is checked again. Null when the person may not add one there, or for people (added by the employee upload).
     *
     * @param  array<string, mixed>  $entry
     * @return array{url: string, label: string}|null
     */
    public function setUp(array $entry): ?array
    {
        $spec = app(MasterCatalogue::class)->for($entry['master']);
        if ($spec->isEmployee() || $this->canCreate($entry)
            || ! in_array($entry['status'], [ResolutionStatus::Unmatched->value, ResolutionStatus::Suggested->value], true)) {
            return null;
        }

        return $this->setUpPages()[$spec->model] ?? null;
    }

    /** "Check again": the person added what was missing on its own screen; the host checks the file again. */
    public function checkAgain(): void
    {
        $this->session = null;
        $this->dispatch('references-resolved', scope: $this->scope, key: '');
    }

    /** @param  array<string, mixed>  $entry */
    public function canRemember(array $entry): bool
    {
        $spec = app(MasterCatalogue::class)->for($entry['master']);

        return ! $spec->isEmployee() && $spec->permission !== null && $this->actor()->hasPermission($spec->permission, $entry['company_id'] ?? null);
    }

    public function render(): View
    {
        $entries = $this->entries();
        $hidden = $this->report['hidden'] ?? [];

        return view('livewire.resolution.reference-review', [
            'entries' => array_slice($entries, 0, $this->shown),
            'more' => max(0, count($entries) - $this->shown),
            'counts' => $this->counts(),
            // Left out of the screen altogether (forReview): too many to settle here; the file is corrected instead.
            'unlisted' => (int) ($hidden['attention'] ?? 0) + (int) ($this->report['overflow'] ?? 0),
            'unlistedMatched' => (int) ($hidden['matched'] ?? 0),
        ]);
    }

    /** @var array<string, array{url: string, label: string}>|null model => where a record of it is added */
    private ?array $pages = null;

    /**
     * The screens on which the person may add records: each panel resource's create page (or its list, which adds in a
     * dialog), when the resource lets them create.
     *
     * @return array<string, array{url: string, label: string}>
     */
    private function setUpPages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }
        $this->pages = [];
        $panel = Filament::getPanel('admin');
        foreach ($panel->getResources() as $resource) {
            try {
                $model = $resource::getModel();
                if (isset($this->pages[$model]) || ! $resource::canCreate()) {
                    continue;
                }
                $page = $resource::hasPage('create') ? 'create' : 'index';
                $this->pages[$model] = ['url' => $resource::getUrl($page, panel: $panel->getId()), 'label' => $resource::getPluralModelLabel()];
            } catch (Throwable) {
                continue; // a resource that cannot answer for this person is simply not offered
            }
        }

        return $this->pages;
    }

    /** @return array<string, mixed>|null */
    private function entry(string $key): ?array
    {
        foreach ($this->report['entries'] ?? [] as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    private function settle(string $key, int $id, string $via, string $record): void
    {
        foreach ($this->report['entries'] as $i => $entry) {
            if ($entry['key'] === $key) {
                $this->report['entries'][$i] = ['status' => ResolutionStatus::Matched->value, 'id' => $id, 'via' => $via, 'record' => $record, 'message' => null, 'candidates' => []] + $entry;
            }
        }
        $this->session = null;
        $this->dispatch('references-resolved', scope: $this->scope, key: $key);
    }

    /** @param  array{name: string, code: ?string}  $row */
    private function label(array $row): string
    {
        return $row['name'].($row['code'] !== null && $row['code'] !== '' && $row['code'] !== $row['name'] ? " ({$row['code']})" : '');
    }

    private function session(): ResolutionSession
    {
        return $this->session ??= app(MasterResolver::class)->session($this->actor(), $this->scope);
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
