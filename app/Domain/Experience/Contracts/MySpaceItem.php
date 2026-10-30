<?php

namespace App\Domain\Experience\Contracts;

use App\Domain\Identity\Models\User;

/**
 * HROne parity MS-1: one of the person's own pages in "My space" (tag: `peopleos.myspace_items`).
 *
 * An item only LINKS. Whether the person sees it is the destination's OWN access rule — the page's or resource's
 * canAccess() (which reads the signed-in person) — never a rule of its own, so My space can neither show a page the person
 * cannot open nor hide one they can (the organisation's `myspace.hidden_items` only leaves an item out of My space; the
 * page itself stays where it was).
 *
 * Registry: Experience\Services\MySpace. Defaults: Experience\MySpaceItems\PageItem (a Filament page or resource), with the
 * built-in entries in Experience\MySpaceItems\SelfPages.
 */
interface MySpaceItem
{
    /** Stable key (`[a-z0-9_.]`, e.g. `payslips`); used for favourites, the hidden-items setting and search results. */
    public function key(): string;

    public function label(): string;

    /** One line shown under the label. */
    public function description(): string;

    /** The My space group: a key of MySpace::GROUPS (profile, time, pay, performance, engage, requests). */
    public function group(): string;

    public function icon(): string;

    /** @return list<string> words the search box and the Command Center also match (HROne's names included) */
    public function keywords(): array;

    /** Whether the signed-in person may open the destination: its own canAccess(). False for anyone else. */
    public function available(User $user): bool;

    /** The destination for the signed-in person (null when it cannot be opened). */
    public function url(User $user): ?string;
}
