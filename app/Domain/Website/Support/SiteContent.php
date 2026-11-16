<?php

namespace App\Domain\Website\Support;

/**
 * A read view of one website document (a revision's content, or the defaults). Sections a stored document lacks, and
 * keys missing inside a section, come from SiteDefaults, so fields added later always have a value. Lists (menus,
 * pages, columns, blocks) are taken as stored: an operator who removed a menu keeps it removed.
 */
final class SiteContent
{
    /** @var array<string, mixed> */
    private array $content;

    /** @param array<string, mixed> $content */
    public function __construct(array $content)
    {
        $this->content = self::normalise($content);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function normalise(array $content): array
    {
        $defaults = SiteDefaults::content();
        foreach (['site', 'announcement', 'sign_in', 'seo', 'footer', 'header'] as $section) {
            $stored = is_array($content[$section] ?? null) ? $content[$section] : [];
            $content[$section] = $stored + $defaults[$section];
        }
        if (! is_array($content['pages'] ?? null)) {
            $content['pages'] = $defaults['pages'];
        }
        $content['pages'] = array_values(array_filter($content['pages'], 'is_array'));
        $content['header']['menus'] = array_values(array_filter((array) $content['header']['menus'], 'is_array'));

        return $content;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->content;
    }

    /** @return array<string, mixed> */
    public function section(string $name): array
    {
        return (array) ($this->content[$name] ?? []);
    }

    public function enabled(): bool
    {
        return (bool) ($this->content['site']['enabled'] ?? false);
    }

    public function name(): string
    {
        return (string) ($this->content['site']['name'] ?: 'PeopleOS');
    }

    /** @return list<array<string, mixed>> */
    public function menus(): array
    {
        return array_values(array_filter($this->content['header']['menus'], fn (array $m) => filled($m['label'] ?? null)));
    }

    /** @return list<array<string, mixed>> */
    public function pages(): array
    {
        return $this->content['pages'];
    }

    /** A published page by its slug ('' = home), or null. Hidden pages are only returned when $includeHidden. */
    public function page(string $slug, bool $includeHidden = false): ?array
    {
        $slug = trim($slug, '/');
        foreach ($this->content['pages'] as $page) {
            if (trim((string) ($page['slug'] ?? ''), '/') === $slug && ($includeHidden || ($page['status'] ?? 'published') === 'published')) {
                return $page;
            }
        }

        return null;
    }

    /** @return list<string> slugs of published pages */
    public function publishedSlugs(): array
    {
        return array_values(array_map(fn (array $p) => trim((string) ($p['slug'] ?? ''), '/'),
            array_filter($this->content['pages'], fn (array $p) => ($p['status'] ?? 'published') === 'published')));
    }

    /**
     * The contact form block on a page (or the first one on the site), for the enquiry's rules.
     *
     * @return array<string, mixed>|null
     */
    public function contactBlock(?string $slug = null): ?array
    {
        $pages = $slug !== null && ($page = $this->page($slug)) !== null ? [$page, ...$this->content['pages']] : $this->content['pages'];
        foreach ($pages as $page) {
            foreach ((array) ($page['blocks'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'contact') {
                    return (array) ($block['data'] ?? []);
                }
            }
        }

        return null;
    }

    /** How a contact form asks for the mobile number: verify (required, checked by an SMS code), optional or hidden. */
    public static function phoneMode(?array $contact): string
    {
        $mode = (string) ($contact['phone'] ?? 'verify');

        return in_array($mode, ['verify', 'optional', 'hidden'], true) ? $mode : 'verify';
    }

    /** @return array<string, mixed> */
    public function signIn(): array
    {
        return $this->content['sign_in'];
    }

    public function allowsIndexing(): bool
    {
        return (bool) ($this->content['seo']['allow_indexing'] ?? false);
    }

    /** The footer's legal line with {year} filled in. */
    public function legal(): string
    {
        return str_replace('{year}', (string) now()->year, (string) ($this->content['footer']['legal'] ?? ''));
    }
}
