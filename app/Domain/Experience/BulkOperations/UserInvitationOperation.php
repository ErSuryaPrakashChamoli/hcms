<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\BulkInvitations;
use App\Filament\Support\WorkforceBulkWizards;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-16 (Users list): "Send new invitations" to invited users (`users.resend_invitations`) or "Send password
 * reset links" to active users (`users.send_reset_links`) — the single-user flows of Users → Edit, for the rows ticked.
 */
abstract class UserInvitationOperation extends BaseBulkOperation
{
    /** `new` (a new invitation) or `reset` (a reset link). */
    protected string $mode = 'reset';

    public function key(): string
    {
        return $this->mode === 'new' ? 'users.resend_invitations' : 'users.send_reset_links';
    }

    public function label(): string
    {
        return $this->mode === 'new' ? 'Send new invitations' : 'Send password reset links';
    }

    public function description(): string
    {
        return $this->mode === 'new'
            ? 'Each invited user who has not accepted yet gets a new invitation; the earlier link stops working. Active or suspended users are refused.'
            : 'Each active user receives a link to choose a new password (you never see it). Invited or suspended users are refused.';
    }

    public function model(): string
    {
        return User::class;
    }

    public function permission(): string
    {
        return 'user.update';
    }

    public function icon(): string
    {
        return $this->mode === 'new' ? 'heroicon-m-envelope' : 'heroicon-m-key';
    }

    public function color(): string
    {
        return 'gray';
    }

    public function with(): array
    {
        return ['tenant'];
    }

    public function schema(): array
    {
        return WorkforceBulkWizards::invitationFields('cc');
    }

    public function prepare(array $data, User $actor): array
    {
        return ['cc' => BulkInvitations::ccAddresses($data['cc'] ?? [])];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var User $record */
        return app(BulkInvitations::class)->userRefusal($record, $this->mode, $actor);
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var User $record */
        return app(BulkInvitations::class)->forUser($record, $this->mode, $actor, $input);
    }

    public function recordLabel(Model $record): string
    {
        /** @var User $record */
        return mb_substr(trim($record->name.' · '.$record->email, ' ·'), 0, 191);
    }
}
