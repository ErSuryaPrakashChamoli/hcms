<?php

namespace App\Support\Masters;

use Illuminate\Database\Eloquent\Model;

/**
 * Master-relationships R1: a master record that something still uses is never deleted. Screens, uploads, the
 * API and any other Eloquent delete get the same refusal (MasterInUseException, with the counts) before the
 * database's own constraint would answer with an error page. Bulk SQL deletes (tenant offboarding) are not
 * Eloquent deletes and are not affected.
 */
trait GuardsMasterDeletion
{
    public static function bootGuardsMasterDeletion(): void
    {
        static::deleting(fn (Model $record) => app(MasterUsage::class)->assertDeletable($record));
    }
}
