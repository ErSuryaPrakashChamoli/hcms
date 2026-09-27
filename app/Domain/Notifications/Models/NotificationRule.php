<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Event -> Rule -> Audience -> Channel -> Template (§47). */
#[Fillable(['tenant_id', 'name', 'event', 'audience', 'channels', 'notification_template_id', 'conditions', 'status'])]
class NotificationRule extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'channels' => 'array',
            'conditions' => 'array',
            'status' => ActiveStatus::class,
        ];
    }

    public function auditModule(): string
    {
        return 'notifications';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }
}
