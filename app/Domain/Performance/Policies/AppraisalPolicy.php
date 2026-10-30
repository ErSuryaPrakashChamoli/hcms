<?php

namespace App\Domain\Performance\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Services\PerformanceRelationships;
use Illuminate\Database\Eloquent\Model;

/**
 * Appraisals follow the employee-owned rules, and (HROne setup parity HP-8d) the approver — the third review level of a
 * cycle with an "Approver review" stage, the manager's own manager — may open the appraisal they review. Being the
 * approver gives no other performance rights.
 */
class AppraisalPolicy extends EmployeeOwnedPolicy
{
    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) || self::isApprover($user, $model);
    }

    public static function isApprover(User $user, Model $model): bool
    {
        $me = app(PerformanceRelationships::class)->forUser($user);

        return $me !== null && $model->getKey() !== null
            && AppraisalReview::query()->where('appraisal_id', $model->getKey())->where('type', 'approver')->where('reviewer_id', $me->id)->exists();
    }
}
