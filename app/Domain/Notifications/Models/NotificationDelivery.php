<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One message to one person on one channel: the tracking record. Not audited (volume). */
#[Fillable(['tenant_id', 'user_id', 'channel', 'event', 'subject', 'body', 'status', 'recipient', 'source_type', 'source_id', 'sent_at', 'read_at', 'error', 'correlation_id', 'dedupe_key'])]
class NotificationDelivery extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['queued' => 'Queued', 'sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed', 'read' => 'Read'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
