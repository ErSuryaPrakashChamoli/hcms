<?php

namespace App\Domain\Performance\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Services\GoalSheetRules;
use App\Domain\Performance\Services\PerformancePolicyRules;
use App\Domain\Performance\Services\PerformanceRelationships;

/**
 * HROne setup parity HP-8b (rating publication, defect 2 of the matrix): who may see an appraisal's ratings, labels,
 * scores, summary and the reviews written by others. HR (performance.view / performance.calibrate) and the employee's
 * performance managers always; the employee only once the rating is published (auto: at finalization, as before;
 * manual: when HR publishes); nobody else (a peer reviewer sees the review they write, not the outcome). The employee's
 * own self review is always theirs to see.
 *
 * HROne setup parity HP-8i (PF-8): the employee's performance policy narrows this per level of the review chain
 * (employee → manager → approver): who sees the ratings and comments of the other levels (all = as above; preceding level;
 * last level; reviewers only; none), who sees the final rating and who sees the final comments (all; all except the
 * employee; the last level; none). HR (performance.view / performance.calibrate) always sees everything; every reviewer
 * sees what they wrote. No policy, or blank answers, keep the rules above exactly.
 */
final class AppraisalVisibility
{
    public static function isPrivileged(Appraisal $appraisal, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->hasPermission('performance.view') || $user->hasPermission('performance.calibrate')) {
            return true;
        }
        $relationships = app(PerformanceRelationships::class);
        $me = $relationships->forUser($user);
        if ($me === null || (int) $me->id === (int) $appraisal->employee_id) {
            return false;
        }

        return (int) $appraisal->manager_id === (int) $me->id
            || ($user->hasPermission('performance.team') && $relationships->manages($me, $appraisal->employee_id))
            // HP-8d: the approver (third review level) reviews the whole appraisal.
            || ($appraisal->relationLoaded('reviews') ? $appraisal->reviews->contains(fn ($r) => $r->type === 'approver' && (int) $r->reviewer_id === (int) $me->id)
                : $appraisal->reviews()->where('type', 'approver')->where('reviewer_id', $me->id)->exists());
    }

    public static function isOwn(Appraisal $appraisal, ?User $user): bool
    {
        $me = $user === null ? null : app(PerformanceRelationships::class)->forUser($user);

        return $me !== null && (int) $me->id === (int) $appraisal->employee_id;
    }

    public static function ratingsVisible(Appraisal $appraisal, ?User $user): bool
    {
        $visible = self::isPrivileged($appraisal, $user) || (self::isOwn($appraisal, $user) && $appraisal->isPublished());

        // HP-8i: "Final Rating is visible to" of the employee's performance policy (all = as above).
        return $visible && self::finalVisible($appraisal, $user, 'final_rating');
    }

    /** HP-8i: the final comments (the summary for the employee): as the final rating, narrowed by "Final comments is visible to". */
    public static function finalCommentsVisible(Appraisal $appraisal, ?User $user): bool
    {
        $visible = self::isPrivileged($appraisal, $user) || (self::isOwn($appraisal, $user) && $appraisal->isPublished());

        return $visible && self::finalVisible($appraisal, $user, 'final_comments');
    }

    public static function isHr(?User $user): bool
    {
        return $user !== null && ($user->hasPermission('performance.view') || $user->hasPermission('performance.calibrate'));
    }

    /** HP-8i: the viewer's level in the appraisal's review chain: self (the employee), manager or approver; null otherwise. */
    public static function levelOf(Appraisal $appraisal, ?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        if (self::isOwn($appraisal, $user)) {
            return 'self';
        }
        $me = app(PerformanceRelationships::class)->forUser($user);
        $reviews = $appraisal->relationLoaded('reviews') ? $appraisal->reviews : $appraisal->reviews()->get();
        if ($me !== null && $reviews->contains(fn ($r) => $r->type === 'approver' && (int) $r->reviewer_id === (int) $me->id)) {
            return 'approver';
        }

        return self::isPrivileged($appraisal, $user) && ! self::isHr($user) ? 'manager' : null;
    }

    /** HP-8i: the last level of the chain — the approver when the appraisal has one, else the manager. */
    public static function lastLevel(Appraisal $appraisal): string
    {
        $reviews = $appraisal->relationLoaded('reviews') ? $appraisal->reviews : $appraisal->reviews()->get();

        return $reviews->contains(fn ($r) => $r->type === 'approver') ? 'approver' : 'manager';
    }

    /**
     * HP-8i: whether the viewer may see a review's ratings and comments. The reviewer always sees their own and HR sees
     * all; otherwise "Ratings and comments are visible to" decides: all — as before (managers and the approver see every
     * review, the employee the submitted ones once published; the self review as the cycle shows it to managers);
     * preceding level — only the level just before the viewer's; last level — only the final reviewer sees the others;
     * reviewers — managers and the approver, never the employee; none — nobody but HR.
     */
    public static function reviewVisible(Appraisal $appraisal, AppraisalReview $review, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        $me = app(PerformanceRelationships::class)->forUser($user);
        if (self::isHr($user) || ($me !== null && (int) $review->reviewer_id === (int) $me->id)) {
            return true;
        }
        if ($review->type === 'self' && ! self::selfReviewVisible($appraisal, $user)) {
            return false;
        }
        $mode = app(PerformancePolicyRules::class)->visibility($appraisal, 'ratings');
        if ($mode === 'all') {
            return self::isPrivileged($appraisal, $user) || (self::isOwn($appraisal, $user) && $appraisal->isPublished() && $review->status === 'submitted');
        }
        $level = self::levelOf($appraisal, $user);
        $chain = PerformancePolicyRules::LEVELS;
        $reviewLevel = array_search($review->type, $chain, true);

        return match ($mode) {
            'preceding_level' => $level !== null && $reviewLevel !== false && $reviewLevel === array_search($level, $chain, true) - 1,
            'last_level' => $level !== null && $level === self::lastLevel($appraisal),
            'reviewer' => in_array($level, ['manager', 'approver'], true),
            default => false,
        };
    }

    /** HP-8i: "Final Rating / Final comments is visible to": all, all except the employee, the last level, none (HR only). */
    private static function finalVisible(Appraisal $appraisal, ?User $user, string $what): bool
    {
        if ($user === null) {
            return false;
        }
        if (self::isHr($user)) {
            return true;
        }

        return match (app(PerformancePolicyRules::class)->visibility($appraisal, $what)) {
            'all_except_employee' => ! self::isOwn($appraisal, $user),
            'last_level' => self::levelOf($appraisal, $user) === self::lastLevel($appraisal),
            'none' => false,
            default => true,
        };
    }

    /**
     * HP-8d (goal sheet policy → Performance scorecard configuration): a part of the scorecard — reviewer_ratings,
     * goal_details, competency_details, goal_score, competency_score, computed_rating — shows to HR and the employee's
     * managers always; to the employee once published, unless their goal sheet policy hides it.
     */
    public static function partVisible(Appraisal $appraisal, ?User $user, string $part): bool
    {
        if (self::isPrivileged($appraisal, $user)) {
            return true;
        }
        if (! self::ratingsVisible($appraisal, $user)) {
            return false;
        }

        return ! in_array($part, app(GoalSheetRules::class)->scorecardHidden($appraisal->loadMissing('employee')->employee), true);
    }

    /** HP-8b: the cycle option "Show self review to manager" (on unless a cycle turns it off). */
    public static function selfReviewVisible(Appraisal $appraisal, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if (self::isOwn($appraisal, $user) || $user->hasPermission('performance.view') || $user->hasPermission('performance.calibrate')) {
            return true;
        }
        $appraisal->loadMissing('cycle');

        return self::isPrivileged($appraisal, $user) && (bool) $appraisal->cycle?->setting('show_self_to_manager', true);
    }
}
