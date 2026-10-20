<?php

namespace App\Domain\Enterprise\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Http\OutboundUrlGuard;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An outbound webhook subscription (§87, §88): URL, HMAC secret, subscribed events. */
#[Fillable(['tenant_id', 'name', 'url', 'secret', 'events', 'status', 'last_delivered_at', 'failure_count'])]
class WebhookEndpoint extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    /** Production readiness closure: a destination that can never be called is refused when saved (the full check runs per request). */
    protected static function booted(): void
    {
        static::saving(function (self $endpoint) {
            if ($endpoint->isDirty('url')) {
                app(OutboundUrlGuard::class)->staticParts((string) $endpoint->url);
            }
        });
    }

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'events' => 'array', 'last_delivered_at' => 'datetime', 'failure_count' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'enterprise';
    }

    public function auditLabel(): string
    {
        return "Webhook {$this->name}";
    }

    public function auditSensitiveAttributes(): array
    {
        return ['secret'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class)->latest('id');
    }

    public function subscribedTo(string $event): bool
    {
        return $this->status === 'active' && (in_array($event, $this->events ?? [], true) || in_array('*', $this->events ?? [], true));
    }
}
