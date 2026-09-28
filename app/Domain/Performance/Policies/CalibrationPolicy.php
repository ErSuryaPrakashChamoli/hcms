<?php

namespace App\Domain\Performance\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Phase 7: calibration sessions and adjustments are confidential — performance.calibrate only; never deleted. */
class CalibrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('performance.calibrate');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('performance.calibrate');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('performance.calibrate');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('performance.calibrate') && $model->getAttribute('status') !== 'closed';
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
