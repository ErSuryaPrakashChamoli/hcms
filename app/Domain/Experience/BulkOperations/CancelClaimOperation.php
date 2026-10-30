<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity RQ-1 (matrix 20 §20.1.19 #18, bulk cancel on the reimbursement list): ReimbursementClaims::cancel on each
 * claim not yet paid, with one reason — what "Cancel" on the register does for payroll (payroll.manage). Nothing is deleted.
 */
final class CancelClaimOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'reimbursement_claims.cancel';
    }

    public function label(): string
    {
        return 'Cancel claims';
    }

    public function description(): string
    {
        return 'Cancels each claim still pending or approved and not yet paid, as "Cancel" on the register does. Paid, rejected or cancelled claims are refused.';
    }

    public function model(): string
    {
        return ReimbursementClaim::class;
    }

    public function permission(): string
    {
        return 'payroll.manage';
    }

    public function icon(): string
    {
        return 'heroicon-m-arrow-uturn-left';
    }

    public function color(): string
    {
        return 'danger';
    }

    public function schema(): array
    {
        return [Textarea::make('reason')->label('Reason')->required()->maxLength(500)];
    }

    public function prepare(array $data, User $actor): array
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for cancelling.');
        }

        return ['reason' => mb_substr($reason, 0, 500)];
    }

    public function with(): array
    {
        return ['employee.person', 'component'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        $status = (string) $record->getAttribute('status');

        return in_array($status, ['pending', 'approved'], true) ? null : 'Only a claim not yet paid can be cancelled (this one is '.(ReimbursementClaim::STATUSES[$status] ?? $status).').';
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var ReimbursementClaim $record */
        app(ReimbursementClaims::class)->cancel($record, (string) $input['reason']);

        return 'Claim cancelled';
    }
}
