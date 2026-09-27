<?php

namespace App\Domain\Compliance\Jobs;

use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;

/** Re-validates and reconciles a return against finalized payroll (Part N), e.g. after payroll was re-finalized. */
class ReconcileStatutoryReturn extends StatutoryReturnJob
{
    public function __construct(public readonly int $returnId, ?int $actorId)
    {
        parent::__construct($actorId);
    }

    public function uniqueId(): string
    {
        return "statutory-reconcile-{$this->tenantId}-{$this->returnId}";
    }

    public function handle(StatutoryReturns $returns): void
    {
        $return = StatutoryReturn::query()->find($this->returnId);

        if ($return !== null && in_array($return->status, [StatutoryReturn::CALCULATED, StatutoryReturn::VALIDATED, StatutoryReturn::RECONCILIATION_REQUIRED], true)) {
            $returns->validate($return, $this->actor(), 'job');
        }
    }
}
