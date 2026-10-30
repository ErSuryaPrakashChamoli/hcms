<?php

namespace App\Domain\Experience\Contracts;

/**
 * HROne setup parity P3: an ApprovalSource whose requests may be sent back to their requester (RequestReturns). Such a
 * request keeps its `pending` status while the return is open (nothing about its balance, overlap or payroll changes),
 * leaves every approver's queue, and comes back when the requester resubmits it; its own approve / reject still work.
 * Optional: a source that does not implement it simply offers no send back.
 */
interface SupportsSendBack
{
    /** @return list<string> the item types whose requests (status `pending`, with a requester) may be sent back */
    public function sendBackTypes(): array;
}
