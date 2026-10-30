<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Models\ConfirmationReview;
use App\Domain\Lifecycle\Services\ConfirmationReviews;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-1 (§23.4 #8 bulk confirm): confirms each pending review through ConfirmationReviews::confirm, whose rules
 * still apply to every one (never one's own confirmation, the manager's feedback first, pending reviews only).
 */
final class ConfirmReviewOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'confirmation_reviews.confirm';
    }

    public function label(): string
    {
        return 'Confirm';
    }

    public function description(): string
    {
        return 'Confirms each employee on their probation end date. Reviews already decided, your own, and those still waiting for the manager\'s feedback are refused.';
    }

    public function model(): string
    {
        return ConfirmationReview::class;
    }

    public function permission(): string
    {
        return 'employee.lifecycle';
    }

    public function icon(): string
    {
        return 'heroicon-m-check-badge';
    }

    public function color(): string
    {
        return 'success';
    }

    public function with(): array
    {
        return ['employee.person'];
    }

    public function schema(): array
    {
        return [Textarea::make('note')->maxLength(1000)];
    }

    public function prepare(array $data, User $actor): array
    {
        return ['note' => filled($data['note'] ?? null) ? mb_substr((string) $data['note'], 0, 1000) : null];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        return $record->getAttribute('status') !== 'pending' ? 'Already decided.' : null;
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var ConfirmationReview $record */
        app(ConfirmationReviews::class)->confirm($record, $input['note'] ?? null, $actor);

        return 'Confirmed';
    }
}
