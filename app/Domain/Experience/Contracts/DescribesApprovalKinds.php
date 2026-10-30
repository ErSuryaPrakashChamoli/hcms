<?php

namespace App\Domain\Experience\Contracts;

/**
 * HROne setup parity P3: an ApprovalSource may name its item types for people (Setup → Inbox actions lists every
 * request type by name). Optional: a source that does not implement it is listed by its type keys.
 */
interface DescribesApprovalKinds
{
    /** @return array<string, string> item type => name, e.g. ['loan' => 'Loan / salary advance'] */
    public function kindLabels(): array;
}
