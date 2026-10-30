<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Services\PerformanceReminders;
use App\Domain\Performance\Support\AppraisalVisibility;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity GO-1 (matrix 27 §27.3.2 #3, HROne "Send Reminder" on review requests): reminds the reviewer of each
 * pending review through PerformanceReminders::remindReviewer — the scheduled reminder's notification and log, so a
 * reviewer is reminded at most once per review and day. Only for performance HR, and only reviews of appraisals in the
 * person's access scope; reviews already submitted, of finalized appraisals or of stages not open yet are refused.
 */
final class RemindReviewersOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'review_requests.remind';
    }

    public function label(): string
    {
        return 'Remind reviewers';
    }

    public function description(): string
    {
        return 'Each reviewer of a pending review is reminded now (at most once a day for each review). Submitted reviews and reviews of finalized appraisals are refused.';
    }

    public function model(): string
    {
        return AppraisalReview::class;
    }

    public function permission(): string
    {
        return 'performance.manage';
    }

    public function icon(): string
    {
        return 'heroicon-m-bell-alert';
    }

    public function color(): string
    {
        return 'gray';
    }

    public function with(): array
    {
        return ['appraisal.employee.person', 'appraisal.cycle', 'reviewer.person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        return self::outsideScope($record, $actor);
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var AppraisalReview $record */
        if (! app(PerformanceReminders::class)->remindReviewer($record)) {
            throw new RuntimeException('Already reminded today.');
        }

        return 'Reminded '.($record->reviewer?->person?->full_name ?? 'the reviewer');
    }

    public function recordLabel(Model $record): string
    {
        return self::reviewLabel($record);
    }

    /** A review is reachable only through its appraisal: HR (performance.view / calibrate) with the appraisal in their scope. */
    public static function outsideScope(Model $record, User $actor): ?string
    {
        if (! AppraisalVisibility::isHr($actor)) {
            return 'You cannot see review requests.';
        }
        $appraisal = Appraisal::query()->withoutGlobalScope(AccessScope::class)->find($record->getAttribute('appraisal_id'));

        return $appraisal === null || ! app(AccessScopes::class)->allows($actor, $appraisal) ? 'Outside your access scope.' : null;
    }

    /** "E001 · Asha Rao · Manager review" */
    public static function reviewLabel(Model $record): string
    {
        /** @var AppraisalReview $record */
        $employee = $record->appraisal?->employee;
        $type = (string) config("peopleos.performance.review_types.{$record->type}", $record->type);

        return mb_substr(trim(($employee?->employee_code ?? '').' · '.($employee?->person?->display_name ?? '').' · '.$type.' review', ' ·'), 0, 191);
    }
}
