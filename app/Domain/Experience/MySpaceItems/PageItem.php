<?php

namespace App\Domain\Experience\MySpaceItems;

use App\Domain\Experience\Contracts\MySpaceItem;
use App\Domain\Identity\Models\User;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use InvalidArgumentException;

/**
 * HROne parity MS-1: a My space item whose destination is a Filament page or a resource's list. Availability is the
 * target's own canAccess() for the signed-in person and nothing else; the URL is the target's own (with optional query
 * parameters, e.g. a My HR tab). A target that is neither a page nor a resource with a list is refused when the item is
 * built, so a mistyped entry fails at once rather than silently.
 */
final class PageItem implements MySpaceItem
{
    /** @var class-string<Page>|class-string<resource> */
    private readonly string $target;

    /**
     * @param  class-string  $target  a Filament page, or a resource (its list is the destination)
     * @param  list<string>  $keywords
     * @param  array<string, scalar>  $parameters  query parameters added to the destination URL
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $group,
        private readonly string $icon,
        string $target,
        private readonly string $description = '',
        private readonly array $keywords = [],
        private readonly array $parameters = [],
    ) {
        if (preg_match('/^[a-z0-9_.]{1,64}$/', $key) !== 1) {
            throw new InvalidArgumentException("My space item key [{$key}] must be 1–64 characters of a-z, 0-9, _ and .");
        }
        if (! is_subclass_of($target, Page::class) && ! (is_subclass_of($target, Resource::class) && array_key_exists('index', $target::getPages()))) {
            throw new InvalidArgumentException("My space item [{$key}] must point at a Filament page or a resource with a list.");
        }
        $this->target = $target;
    }

    /**
     * Build an item from a definition (Experience\MySpaceItems\SelfPages::ITEMS).
     *
     * @param  array{label: string, group: string, icon: string, target: class-string, description?: string, keywords?: list<string>, parameters?: array<string, scalar>}  $definition
     */
    public static function fromDefinition(string $key, array $definition): self
    {
        return new self($key, $definition['label'], $definition['group'], $definition['icon'], $definition['target'],
            $definition['description'] ?? '', $definition['keywords'] ?? [], $definition['parameters'] ?? []);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function group(): string
    {
        return $this->group;
    }

    public function icon(): string
    {
        return $this->icon;
    }

    public function keywords(): array
    {
        return $this->keywords;
    }

    /** @return class-string the page or resource the item opens */
    public function target(): string
    {
        return $this->target;
    }

    public function available(User $user): bool
    {
        return $this->signedIn($user) && $this->target::canAccess();
    }

    public function url(User $user): ?string
    {
        if (! $this->available($user)) {
            return null;
        }

        return is_subclass_of($this->target, Resource::class)
            ? $this->target::getUrl('index', $this->parameters)
            : $this->target::getUrl($this->parameters);
    }

    private function signedIn(User $user): bool
    {
        return auth()->check() && (int) auth()->id() === (int) $user->getKey();
    }
}
