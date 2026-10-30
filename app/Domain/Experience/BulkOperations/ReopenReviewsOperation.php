<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Services\Appraisals;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity GO-1 (matrix 27 §27.3.1 #7 / §27.3.2 #3, HROne "Review Reinitiate all"; owner decision PER-1 / D-GO1):
 * reopens each submitted review through Appraisals::reopenReview with one reason for the run. Offered only while the
 * Setup centre setting `performance.review.hr_reopen` is on (off by default) and refused per review otherwise; the
 * service's own rules apply to each one (performance.manage, before finalization, never one's own review, the earlier
 * submission kept in the audit trail, the reviewer told).
 */
final class ReopenReviewsOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'review_requests.reopen';
    }

    public function label(): string
    {
        return 'Reopen reviews';
    }

    public function description(): string
    {
        return 'Each submitted review goes back to its reviewer to fill in again; the earlier submission stays in the audit trail and the reviewer is told. Reviews of finalized appraisals, your own and those not submitted are refused.';
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
        return 'heroicon-m-arrow-uturn-left';
    }

    public function color(): string
    {
        return 'warning';
    }

    public function with(): array
    {
        return ['appraisal.employee.person', 'reviewer.person'];
    }

    public function schema(): array
    {
        return [Textarea::make('reason')->label('Why are the reviews reopened?')->required()->maxLength(500)];
    }

    public function prepare(array $data, User $actor): array
    {
        if (! Appraisals::reopenEnabled()) {
            throw new RuntimeException('Reopening submitted reviews is turned off (Setup → Performance).');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Say why the reviews are reopened.');
        }

        return ['reason' => mb_substr($reason, 0, 500)];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        if (! Appraisals::reopenEnabled()) {
            return 'Reopening submitted reviews is turned off.';
        }

        return RemindReviewersOperation::outsideScope($record, $actor);
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var AppraisalReview $record */
        app(Appraisals::class)->reopenReview($record, (string) ($input['reason'] ?? ''), $actor);

        return 'Reopened';
    }

    public function recordLabel(Model $record): string
    {
        return RemindReviewersOperation::reviewLabel($record);
    }
}
