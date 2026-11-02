<?php

namespace App\Filament\Support\Pages;

use App\Domain\Experience\Models\BulkRun;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use Closure;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity WO-1: an operations desk — one page that orders a domain's existing registers by the journey they serve
 * (the HROne Workforce panel: People · Onboarding · Confirmations · Transfer · Separation · Letters · Reports), each entry
 * linking the existing resource or page (with a preset view where one exists). It adds no register of its own and grants
 * nothing: an entry is listed only when its screen's own canAccess() lets the viewer in, and counts come from the
 * register's own (scoped) query.
 *
 * Reused by the time office, payroll, expense / asset and performance desks: extend it, give sections() and
 * registers() (the register keys whose recent bulk runs the desk lists), and register the page.
 */
abstract class OperationsDeskPage extends Page
{
    protected string $view = 'filament.pages.operations-desk';

    /**
     * HROne parity WO-16: an entry may carry `visible` (a closure; false = not listed), for an entry that opens an action
     * on its target page (`params: ['action' => …]`) which only some of the target's viewers may run.
     *
     * @return list<array{label: string, description?: string, items: list<array{label: string, target: class-string, params?: array<string, mixed>, description?: string, count?: Closure(): int, visible?: Closure(): bool}>}>
     */
    abstract protected function sections(): array;

    /**
     * HROne parity TX-8a: reminders shown above the desk's sections (e.g. the leave year closing with leave still to carry
     * forward). None by default; each desk decides who sees its own.
     *
     * @return list<array{key: string, title: string, body: string, url?: string|null, action?: string|null}>
     */
    public function notices(): array
    {
        return [];
    }

    /** @return list<string> register keys of this desk (recent bulk runs). */
    protected function registers(): array
    {
        return [];
    }

    /** Whether any entry of the desk opens for the viewer (no counts: this runs whenever the navigation is built). */
    public static function anyEntryOpens(): bool
    {
        foreach ((new static)->sections() as $section) {
            foreach ($section['items'] as $item) {
                if (self::listed($item) && self::urlFor($item['target'], $item['params'] ?? []) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The sections and entries the viewer can open, with their urls (and counts where given).
     *
     * @return list<array{label: string, description: ?string, items: list<array{label: string, url: string, description: ?string, count: ?int}>}>
     */
    public function deskSections(): array
    {
        $out = [];
        foreach ($this->sections() as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                $url = self::listed($item) ? self::urlFor($item['target'], $item['params'] ?? []) : null;
                if ($url === null) {
                    continue;
                }
                $count = isset($item['count']) ? rescue(fn () => (int) ($item['count'])(), null, false) : null;
                $items[] = ['label' => $item['label'], 'url' => $url, 'description' => $item['description'] ?? null, 'count' => $count];
            }
            if ($items !== []) {
                $out[] = ['label' => $section['label'], 'description' => $section['description'] ?? null, 'items' => $items];
            }
        }

        return $out;
    }

    /** @return Collection<int, BulkRun> the viewer's latest bulk runs on this desk's registers */
    public function recentRuns(): Collection
    {
        $user = auth()->user();
        if ($user === null || $this->registers() === [] || ! BulkRunResource::canAccess()) {
            return collect();
        }

        return BulkRun::query()->where('user_id', $user->id)->whereIn('register', $this->registers())->latest('id')->limit(5)->get();
    }

    public function runUrl(BulkRun $run): string
    {
        return BulkRunResource::getUrl('view', ['record' => $run]);
    }

    /** @param  array<string, mixed>  $item */
    private static function listed(array $item): bool
    {
        return ! isset($item['visible']) || (bool) rescue(fn () => ($item['visible'])(), false, false);
    }

    /** @param  array<string, mixed>  $params */
    private static function urlFor(string $target, array $params): ?string
    {
        try {
            if (is_subclass_of($target, Resource::class)) {
                return $target::canAccess() && array_key_exists('index', $target::getPages()) ? $target::getUrl('index', $params) : null;
            }
            if (is_subclass_of($target, Page::class)) {
                return $target::canAccess() ? $target::getUrl($params) : null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
