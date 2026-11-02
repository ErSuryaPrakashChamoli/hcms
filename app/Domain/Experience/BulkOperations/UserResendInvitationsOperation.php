<?php

namespace App\Domain\Experience\BulkOperations;

/** HROne parity WO-16 (Users list): a new invitation for each invited user ticked (UserInvitations::issue). */
final class UserResendInvitationsOperation extends UserInvitationOperation
{
    protected string $mode = 'new';
}
