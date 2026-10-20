<?php

namespace App\Domain\Platform\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * A tenant's operational state.
 *
 * - trial / active: in use.
 * - suspended: an operator stopped access (SaaS.2, SaaS.8); reactivation restores the interrupted status.
 * - closed (SaaS.9): the customer has left; the service ended, nobody signs in, data is retained under the retention
 *   policies until its deletion is approved and executed. An operator may reopen a closed tenant until a deletion starts.
 * - deleted (SaaS.9): every deletable category has been deleted; the row is the tombstone that keeps the retained records
 *   (Markedge's commercial records, the exit's evidence) and the deletion certificates.
 *
 * Only trial and active tenants are accessible: every entry point (sign-in, sessions, API keys, SSO, invitations,
 * password reset, downloads, queued and scheduled work) goes through allowsAccess().
 */
enum TenantStatus: string implements HasColor, HasLabel
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
    case Deleted = 'deleted';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Trial => 'info',
            self::Active => 'success',
            self::Suspended => 'danger',
            self::Closed => 'warning',
            self::Deleted => 'gray',
        };
    }

    public function allowsAccess(): bool
    {
        return $this === self::Trial || $this === self::Active;
    }

    /** Whether the tenant is in use (an operator may suspend it, offboard it). */
    public function operating(): bool
    {
        return $this === self::Trial || $this === self::Active;
    }
}
