<?php

namespace App\Domain\Leave\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveEncashmentBatch;

/**
 * HROne parity PO-8: encashment batches are seen by leave viewers (the encashments inside stay within each viewer's access
 * scope) and made, decided and unprocessed by people who manage leave; the service refuses the rest (one's own batch, a
 * batch one is in, employees out of reach). Never deleted: a batch is withdrawn, rejected or unprocessed.
 */
class LeaveEncashmentBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('leave.view');
    }

    public function view(User $user, LeaveEncashmentBatch $batch): bool
    {
        return $user->hasPermission('leave.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('leave.manage');
    }

    public function update(User $user, LeaveEncashmentBatch $batch): bool
    {
        return $user->hasPermission('leave.manage');
    }

    public function delete(User $user, LeaveEncashmentBatch $batch): bool
    {
        return false;
    }
}
