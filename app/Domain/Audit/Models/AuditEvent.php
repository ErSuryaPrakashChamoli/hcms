<?php

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Builders\ImmutableBuilder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Exceptions\ImmutableAuditRecordException;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Append-only audit event. Reads are tenant-scoped; writes happen only through AuditRecorder.
 */
class AuditEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    /** Microsecond precision so the hash chain and ordering survive a round trip on every driver. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    /** Mass updates and deletes through the query builder are refused as well as model events. */
    public function newEloquentBuilder($query): ImmutableBuilder
    {
        return new ImmutableBuilder($query);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::updating(fn () => throw ImmutableAuditRecordException::because('update'));
        static::deleting(fn () => throw ImmutableAuditRecordException::because('delete'));
    }

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'actor_roles' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'effective_date' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function fieldChanges(): HasMany
    {
        return $this->hasMany(AuditEventChange::class)->orderBy('id');
    }

    /**
     * Canonical string that is hashed. Any change to this format invalidates existing chains,
     * so treat it as part of the storage schema.
     *
     * @param  array<int, array{field: string, before: ?string, after: ?string}>  $changes
     */
    public static function canonicalPayload(array $attributes, array $changes): string
    {
        usort($changes, fn (array $a, array $b) => strcmp($a['field'], $b['field']));

        $payload = [
            'id' => $attributes['id'],
            'tenant_id' => $attributes['tenant_id'],
            'actor_id' => $attributes['actor_id'],
            'action' => $attributes['action'],
            'module' => $attributes['module'],
            'entity_type' => $attributes['entity_type'],
            'entity_id' => $attributes['entity_id'],
            'occurred_at' => $attributes['occurred_at'],
            'source' => $attributes['source'],
            'request_id' => $attributes['request_id'],
            'reason' => $attributes['reason'],
            'approval_reference' => $attributes['approval_reference'],
            'effective_date' => $attributes['effective_date'],
            // JSON columns (MySQL in particular) do not preserve object key order, so the
            // canonical form sorts keys recursively.
            'metadata' => self::sortKeysRecursively($attributes['metadata']),
            'changes' => array_map(fn (array $c) => [
                'field' => $c['field'],
                'before' => $c['before'],
                'after' => $c['after'],
            ], $changes),
        ];

        // Added in Phase 0.2; only present when set so chains written before it still verify.
        if (! empty($attributes['operation_id'])) {
            $payload['operation_id'] = $attributes['operation_id'];
        }

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sortKeysRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map(fn ($v) => self::sortKeysRecursively($v), $value);

        if (! array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }

    public static function computeHash(?string $previousHash, string $canonicalPayload): string
    {
        return hash('sha256', ($previousHash ?? '').'|'.$canonicalPayload);
    }

    /**
     * Rebuild this event's hash from its stored data (used by the integrity verifier).
     */
    public function recomputeHash(): string
    {
        $changes = $this->fieldChanges->map(fn (AuditEventChange $c) => [
            'field' => $c->field,
            'before' => $c->before,
            'after' => $c->after,
        ])->all();

        $attributes = [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'actor_id' => $this->actor_id,
            'action' => $this->action->value,
            'module' => $this->module,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'occurred_at' => $this->occurred_at->format('Y-m-d H:i:s.u'),
            'source' => $this->source,
            'request_id' => $this->request_id,
            'reason' => $this->reason,
            'approval_reference' => $this->approval_reference,
            'effective_date' => $this->effective_date?->toDateString(),
            'metadata' => $this->metadata,
            'operation_id' => $this->operation_id,
        ];

        return self::computeHash($this->previous_hash, self::canonicalPayload($attributes, $changes));
    }
}
