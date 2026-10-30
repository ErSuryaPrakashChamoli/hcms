<?php

namespace App\Domain\Experience\Contracts;

use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;

/**
 * A system of record that adds decisions to the Approval Center (HROne parity). Sources are tagged
 * `peopleos.approval_sources` in the container; the center lists their items next to its own, and
 * ApprovalDecisions sends each decision back to the source whose `types()` include the item's type.
 * A source lists only what the user may decide and its domain services check again when deciding.
 */
interface ApprovalSource
{
    /** @return list<string> the ApprovalItem types this source owns */
    public function types(): array;

    /** @return list<ApprovalItem> decisions waiting for the user (about one employee when $about is given) */
    public function pending(User $user, ?int $about = null): array;

    /** Apply the decision through the owning domain service; return a short confirmation. */
    public function decide(ApprovalItem $item, User $user, string $decision, ?string $note): string;
}
