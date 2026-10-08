<?php

namespace App\Domain\ServiceDesk\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 12: one HR service in the catalogue (identity, category, subcategory). What the service asks,
 * who may use it, its approval, SLA, assignment and domain hand-off live on its effective-dated
 * versions; a request is pinned to the version it was raised under.
 */
#[Fillable(['tenant_id', 'code', 'name', 'ticket_category_id', 'subcategory', 'status', 'sort_order'])]
class ServiceDefinition extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'sort_order' => 0];

    protected static function booted(): void
    {
        static::saving(fn (self $s) => $s->code = strtoupper(trim((string) $s->code)));
        static::deleting(function (): void {
            throw new \RuntimeException('Services are never deleted; deactivate the service or archive its versions.');
        });
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'servicedesk';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'ticket_category_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ServiceDefinitionVersion::class)->orderByDesc('version');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
