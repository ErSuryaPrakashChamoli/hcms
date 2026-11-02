<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\BulkInvitations;
use App\Filament\Support\WorkforceBulkWizards;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-16 (§23.9 #7–8, #10 "Send to new user"): create a sign-in from each employee's work e-mail and send the
 * existing invitation (UserInvitations), or a new invitation to one still invited; optional CC notice without the link.
 */
final class InviteUsersOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'employees.invite_users';
    }

    public function label(): string
    {
        return 'Invite to sign in';
    }

    public function description(): string
    {
        return 'Each employee without a sign-in gets one from their work e-mail and an invitation to choose a password (someone still invited gets a new invitation). Employees with no work e-mail, an address used by another user, an active sign-in, or who have left are refused with the reason.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'user.create';
    }

    public function icon(): string
    {
        return 'heroicon-m-envelope-open';
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
        return WorkforceBulkWizards::invitationFields('new');
    }

    public function prepare(array $data, User $actor): array
    {
        return app(BulkInvitations::class)->prepare('new', $data, $actor);
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var Employee $record */
        return app(BulkInvitations::class)->refusal($record, 'new', $actor);
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        return app(BulkInvitations::class)->invite($record, $actor, $input);
    }
}
