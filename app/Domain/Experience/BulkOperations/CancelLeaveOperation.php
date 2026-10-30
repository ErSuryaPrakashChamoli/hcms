<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Services\Leaves;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity RQ-1 (matrix 20 §20.1.19 #4, bulk "Cancel all" on the leave list): Leaves::cancel on each leave request still
 * open (pending, approved or with a cancellation waiting), with one reason — what "Cancel" on the register does for HR
 * (leave.manage, the record's `cancel` policy in scope). Nothing is deleted; the balance follows the leave ledger.
 */
final class CancelLeaveOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'leave_requests.cancel';
    }

    public function label(): string
    {
        return 'Cancel leave';
    }

    public function description(): string
    {
        return 'Cancels each leave request still pending or approved, as "Cancel" on the register does; the balance is restored through the leave ledger. Leave already cancelled or rejected is refused.';
    }

    public function model(): string
    {
        return LeaveRequest::class;
    }

    public function permission(): string
    {
        return 'leave.manage';
    }

    public function icon(): string
    {
        return 'heroicon-m-no-symbol';
    }

    public function color(): string
    {
        return 'danger';
    }

    public function schema(): array
    {
        return [Textarea::make('reason')->label('Reason')->required()->maxLength(255)];
    }

    public function prepare(array $data, User $actor): array
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for cancelling.');
        }

        return ['reason' => mb_substr($reason, 0, 255)];
    }

    public function with(): array
    {
        return ['employee.person', 'leaveType'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var LeaveRequest $record */
        if (! $record->isOpen()) {
            return 'Only leave still pending or approved can be cancelled (this one is '.(LeaveRequest::STATUSES[$record->status] ?? $record->status).').';
        }

        return $actor->can('cancel', $record) ? null : 'You may not cancel this leave.';
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var LeaveRequest $record */
        $result = app(Leaves::class)->cancel($record, (string) $input['reason'], $actor);

        return 'Leave '.($result->status === 'cancel_requested' ? 'cancellation requested' : 'cancelled');
    }
}
