<?php

namespace App\Domain\Experience\BulkOperations;

/** HROne parity WO-16 (Users list, §23.9 #17): a password reset link for each active user ticked (PasswordResets::sendNow). */
final class UserResetLinksOperation extends UserInvitationOperation
{
    protected string $mode = 'reset';
}
