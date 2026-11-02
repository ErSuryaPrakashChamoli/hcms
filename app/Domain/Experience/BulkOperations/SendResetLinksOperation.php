<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\BulkInvitations;
use App\Filament\Support\WorkforceBulkWizards;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-16 (§23.9 #8 "Reset for existing user", #17 the bulk reset-link form): the existing password reset link
 * (PasswordResets) to each employee with an active sign-in; optional CC notice without the link.
 */
final class SendResetLinksOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'employees.send_reset_links';
    }

    public function label(): string
    {
        return 'Send password reset links';
    }

    public function description(): string
    {
        return 'Each employee with an active sign-in receives a link to choose a new password (you never see it). Employees without a sign-in, still invited, suspended or who have left are refused with the reason.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'user.update';
    }

    public function icon(): string
    {
        return 'heroicon-m-key';
    }

    public function color(): string
    {
        return 'gray';
    }

    public function with(): array
    {
        return ['person'];
    }

    public function schema(): array
    {
        return WorkforceBulkWizards::invitationFields('reset');
    }

    public function prepare(array $data, User $actor): array
    {
        return app(BulkInvitations::class)->prepare('reset', $data, $actor);
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var Employee $record */
        return app(BulkInvitations::class)->refusal($record, 'reset', $actor);
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        return app(BulkInvitations::class)->sendReset($record, $actor, $input);
    }
}
