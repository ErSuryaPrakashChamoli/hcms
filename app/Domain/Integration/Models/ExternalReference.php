<?php

namespace App\Domain\Integration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Integration\Support\LinkableEntities;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 14 (ADR-0011): one external system's identifier for one PeopleOS record.
 *
 * PeopleOS ids stay canonical: the reference points *to* the PeopleOS record and is never used as a
 * key. An external id belongs to exactly one PeopleOS record per system and type (unique); a record
 * may carry many references. References are retired, never re-pointed.
 */
#[Fillable(['tenant_id', 'integration_system_id', 'entity_type', 'entity_id', 'external_entity_type', 'external_entity_id', 'external_reference', 'metadata', 'status', 'linked_by'])]
class ExternalReference extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::updating(function (self $r): void {
            if (array_intersect(array_keys($r->getDirty()), ['integration_system_id', 'entity_type', 'entity_id', 'external_entity_type', 'external_entity_id']) !== []) {
                throw new \RuntimeException('An external reference is never re-pointed; retire it and link a new one.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('External references are retired, never deleted.'));
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'entity_id' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'integration';
    }

    public function auditLabel(): string
    {
        return "{$this->external_entity_type}:{$this->external_entity_id} → {$this->entity_type}#{$this->entity_id}";
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(IntegrationSystem::class, 'integration_system_id');
    }

    /** The PeopleOS record (entity_type is a stable alias from LinkableEntities, never a class name). */
    public function entityModel(): ?Model
    {
        return app(LinkableEntities::class)->find($this->entity_type, (int) $this->entity_id);
    }
}
