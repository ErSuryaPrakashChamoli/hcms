<?php

namespace App\Domain\Website\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Website\Enums\RevisionStatus;
use App\Domain\Website\Models\WebsiteRevision;
use App\Domain\Website\Support\SiteContent;
use App\Domain\Website\Support\SiteDefaults;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Throwable;

/**
 * The public website (platform configuration): the marketing site at "/", its header menus and pages, the footer and
 * the sign-in page's PeopleOS copy. Operators edit one shared draft (Platform → Public website), preview it, and
 * publish it; visitors only ever see the published revision. Until the first publish the site is not live and "/"
 * goes to sign-in, as before. Every change is audited on the platform chain.
 */
final class Website
{
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*){0,2}';

    /** Sections operators edit, with the labels used in the editor and the audit trail. */
    public const AREAS = [
        'site' => 'Site and brand',
        'announcement' => 'Announcement bar',
        'header' => 'Header and menus',
        'pages' => 'Pages',
        'footer' => 'Footer',
        'sign_in' => 'Sign-in page',
        'seo' => 'Search engines and sharing',
    ];

    /** Files and folders served from public/ that a page slug must never shadow. */
    private const PUBLIC_PATHS = ['build', 'css', 'js', 'fonts', 'vendor', 'storage', 'images', 'favicon.ico', 'favicon.svg', 'robots.txt', 'sitemap.xml', 'index.php'];

    private const MEMO = 'peopleos.website.live';

    public function __construct(private readonly AuditRecorder $audit) {}

    /** The live revision, or null before the first publish. Read once per request. */
    public function live(): ?WebsiteRevision
    {
        $request = app()->bound('request') ? request() : null;
        if ($request !== null && $request->attributes->has(self::MEMO)) {
            return $request->attributes->get(self::MEMO);
        }
        $live = WebsiteRevision::query()->where('status', RevisionStatus::Published->value)->latest('published_at')->first();
        $request?->attributes->set(self::MEMO, $live);

        return $live;
    }

    /** What visitors see: the live content, or the defaults before the first publish. */
    public function published(): SiteContent
    {
        return new SiteContent($this->live()?->content ?? SiteDefaults::content());
    }

    /** True when "/" and the site pages are served: something is published and the site is switched on. */
    public function isLive(): bool
    {
        return $this->live() !== null && $this->published()->enabled();
    }

    /**
     * The sign-in page's PeopleOS copy (headline, highlight, text, notice, help link, app badges) as published. Never
     * throws: the sign-in page must render even if the website tables are unreachable (the defaults are used then).
     */
    public function signIn(): array
    {
        try {
            return $this->published()->signIn();
        } catch (Throwable $e) {
            report($e);

            return SiteDefaults::content()['sign_in'];
        }
    }

    /** Whether the public website is live, for links back to it from the sign-in page; false on any failure. */
    public function isLiveSafely(): bool
    {
        try {
            return $this->isLive();
        } catch (Throwable) {
            return false;
        }
    }

    public function draftRevision(): ?WebsiteRevision
    {
        return WebsiteRevision::query()->where('status', RevisionStatus::Draft->value)->oldest('id')->first();
    }

    /** What operators are editing: the draft, else the live content, else the defaults. */
    public function draft(): SiteContent
    {
        return new SiteContent($this->draftRevision()?->content ?? $this->live()?->content ?? SiteDefaults::content());
    }

    /** Replace one area of the draft (creating the draft from the live site first). */
    public function saveArea(string $area, array $value, User $actor, ?string $detail = null): void
    {
        if (! array_key_exists($area, self::AREAS)) {
            throw new InvalidArgumentException("Unknown website area {$area}.");
        }
        $this->mutateDraft($actor, function (array $content) use ($area, $value): array {
            if ($area === 'pages') {
                $content['pages'] = array_values($value);
            } else {
                $content[$area] = $value + ($content[$area] ?? []);
            }

            return $content;
        }, $area, $detail);
    }

    /** Add (null index) or replace one header menu. */
    public function saveMenu(?int $index, array $menu, User $actor): void
    {
        $this->mutateDraft($actor, function (array $content) use ($index, $menu): array {
            $menus = $content['header']['menus'];
            if ($index === null || ! array_key_exists($index, $menus)) {
                $menus[] = $menu;
            } else {
                $menus[$index] = $menu;
            }
            $content['header']['menus'] = array_values($menus);

            return $content;
        }, 'header', 'Menu "'.($menu['label'] ?? '').'"');
    }

    public function deleteMenu(int $index, User $actor): void
    {
        $this->mutateDraft($actor, function (array $content) use ($index): array {
            unset($content['header']['menus'][$index]);
            $content['header']['menus'] = array_values($content['header']['menus']);

            return $content;
        }, 'header', 'Menu removed');
    }

    public function moveMenu(int $index, int $by, User $actor): void
    {
        $this->mutateDraft($actor, function (array $content) use ($index, $by): array {
            $content['header']['menus'] = $this->move($content['header']['menus'], $index, $by);

            return $content;
        }, 'header', 'Menu order');
    }

    /**
     * Add (null original slug) or replace one page. The slug must be free and must not shadow a workspace route.
     *
     * @throws InvalidArgumentException with a message for the operator
     */
    public function savePage(?string $originalSlug, array $page, User $actor): void
    {
        $slug = trim(mb_strtolower((string) ($page['slug'] ?? '')), '/');
        $page['slug'] = $slug;
        $this->assertSlugAllowed($slug);

        $this->mutateDraft($actor, function (array $content) use ($originalSlug, $page, $slug): array {
            $pages = $content['pages'];
            $at = null;
            foreach ($pages as $i => $existing) {
                $existingSlug = trim((string) ($existing['slug'] ?? ''), '/');
                if ($originalSlug !== null && $existingSlug === trim($originalSlug, '/')) {
                    $at = $i;
                } elseif ($existingSlug === $slug) {
                    throw new InvalidArgumentException($slug === '' ? 'The site already has a home page.' : "Another page already uses /{$slug}.");
                }
            }
            if ($at === null) {
                $pages[] = $page;
            } else {
                $pages[$at] = $page;
            }
            $content['pages'] = array_values($pages);

            return $content;
        }, 'pages', 'Page "'.($page['title'] ?? $slug).'"');
    }

    public function deletePage(string $slug, User $actor): void
    {
        if (trim($slug, '/') === '') {
            throw new InvalidArgumentException('The home page cannot be removed; hide the site instead (Site and brand).');
        }
        $this->mutateDraft($actor, function (array $content) use ($slug): array {
            $content['pages'] = array_values(array_filter($content['pages'], fn (array $p) => trim((string) ($p['slug'] ?? ''), '/') !== trim($slug, '/')));

            return $content;
        }, 'pages', "Page /{$slug} removed");
    }

    /**
     * Whether Publish has something to do: a draft, or (on a fresh server where nothing was ever published) the default
     * site, so the first publish does not need a throw-away edit first.
     */
    public function canPublish(): bool
    {
        return $this->draftRevision() !== null || $this->live() === null;
    }

    /** Publish the draft (or the default site, on the first publish): it becomes the live site and the previous live copy becomes history. */
    public function publish(User $actor, ?string $note = null): WebsiteRevision
    {
        $revision = $this->locked(function () use ($actor, $note) {
            return DB::transaction(function () use ($actor, $note) {
                $draft = WebsiteRevision::query()->where('status', RevisionStatus::Draft->value)->oldest('id')->lockForUpdate()->first();
                if ($draft === null && WebsiteRevision::query()->where('status', RevisionStatus::Published->value)->doesntExist()) {
                    $draft = WebsiteRevision::query()->create(['status' => RevisionStatus::Draft, 'content' => SiteContent::normalise(SiteDefaults::content()), 'created_by' => $actor->id, 'updated_by' => $actor->id]);
                }
                if ($draft === null) {
                    throw new InvalidArgumentException('There are no unpublished changes.');
                }
                WebsiteRevision::query()->where('status', RevisionStatus::Published->value)->update(['status' => RevisionStatus::Archived->value]);
                $draft->forceFill([
                    'status' => RevisionStatus::Published, 'note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null,
                    'published_by' => $actor->id, 'published_at' => now(), 'content' => SiteContent::normalise($draft->content ?? []),
                ])->save();

                return $draft;
            });
        });
        $this->forget();
        $this->record(AuditAction::StatusChange, $actor, ['status' => 'published', 'revision_id' => $revision->id, 'note' => $revision->note]);

        return $revision;
    }

    /** Throw the draft away: the editor shows the live site again. */
    public function discardDraft(User $actor): void
    {
        $deleted = WebsiteRevision::query()->where('status', RevisionStatus::Draft->value)->delete();
        if ($deleted > 0) {
            $this->record(AuditAction::Delete, $actor, ['status' => 'draft discarded']);
        }
    }

    /** Copy an earlier (or the live) revision into the draft, to review and publish again. */
    public function restore(WebsiteRevision $revision, User $actor): void
    {
        if ($revision->status === RevisionStatus::Draft) {
            return;
        }
        $this->mutateDraft($actor, fn () => SiteContent::normalise($revision->content ?? []), 'restore', "Revision #{$revision->id}", AuditAction::Restore);
    }

    /** @return Collection<int, WebsiteRevision> the live revision and earlier ones, newest first */
    public function history(int $limit = 25): Collection
    {
        return WebsiteRevision::query()->with(['publisher'])
            ->whereIn('status', [RevisionStatus::Published->value, RevisionStatus::Archived->value])
            ->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get();
    }

    /** @return list<string> the editor's area labels whose draft content differs from the live site */
    public function changedAreas(): array
    {
        $draft = $this->draftRevision();
        if ($draft === null) {
            return [];
        }
        $live = (new SiteContent($this->live()?->content ?? []))->toArray();
        $new = (new SiteContent($draft->content ?? []))->toArray();
        if ($this->live() === null) {
            return array_values(self::AREAS);
        }

        return array_values(array_map(fn (string $area) => self::AREAS[$area],
            array_filter(array_keys(self::AREAS), fn (string $area) => json_encode($live[$area] ?? null) !== json_encode($new[$area] ?? null))));
    }

    /** @throws InvalidArgumentException */
    public function assertSlugAllowed(string $slug): void
    {
        if ($slug === '') {
            return;
        }
        if (! preg_match('#^'.self::SLUG_PATTERN.'$#', $slug)) {
            throw new InvalidArgumentException('Use lower-case letters, numbers and hyphens, with up to three parts separated by "/" (e.g. product/payroll).');
        }
        $first = explode('/', $slug)[0];
        if (in_array($first, $this->reservedSlugs(), true)) {
            throw new InvalidArgumentException("/{$first} is used by the workspace; choose another address.");
        }
    }

    /** @return list<string> first path segments the workspace or public/ already uses */
    public function reservedSlugs(): array
    {
        $segments = collect(Route::getRoutes()->getRoutes())
            ->reject(fn ($route) => $route->getName() === 'website.page')
            ->map(fn ($route) => explode('/', trim($route->uri(), '/'))[0])
            ->filter(fn (string $segment) => $segment !== '' && ! str_starts_with($segment, '{'));

        return $segments->merge(self::PUBLIC_PATHS)->unique()->values()->all();
    }

    /** @param callable(array): array $change */
    private function mutateDraft(User $actor, callable $change, string $area, ?string $detail, AuditAction $action = AuditAction::SettingChanged): void
    {
        $this->locked(function () use ($actor, $change) {
            DB::transaction(function () use ($actor, $change) {
                $draft = WebsiteRevision::query()->where('status', RevisionStatus::Draft->value)->oldest('id')->lockForUpdate()->first();
                $base = $draft?->content ?? $this->live()?->content ?? SiteDefaults::content();
                $content = SiteContent::normalise($change(SiteContent::normalise($base)));
                if ($draft === null) {
                    WebsiteRevision::query()->create(['status' => RevisionStatus::Draft, 'content' => $content, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
                } else {
                    $draft->forceFill(['content' => $content, 'updated_by' => $actor->id])->save();
                }
            });
        });
        $this->record($action, $actor, ['area' => self::AREAS[$area] ?? $area, 'detail' => $detail]);
    }

    /** One editor at a time changes the draft (a lock row, not a gap lock on an empty status range). */
    private function locked(callable $callback): mixed
    {
        return Cache::lock('peopleos:website:draft', 15)->block(10, $callback);
    }

    /** @param list<array> $items */
    private function move(array $items, int $index, int $by): array
    {
        $items = array_values($items);
        $to = $index + $by;
        if (! array_key_exists($index, $items) || $to < 0 || $to >= count($items)) {
            return $items;
        }
        [$items[$index], $items[$to]] = [$items[$to], $items[$index]];

        return $items;
    }

    private function forget(): void
    {
        if (app()->bound('request')) {
            request()->attributes->remove(self::MEMO);
        }
    }

    private function record(AuditAction $action, User $actor, array $metadata): void
    {
        $this->audit->record($action, 'website', null, [], null, entityLabel: 'Public website', actor: $actor,
            metadata: array_filter($metadata, fn ($v) => $v !== null), platform: true);
    }
}
