<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Configuration\Cloning\CloneRegistry;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One clone attempt (configuration cloning, docs/architecture/configuration-cloning.md): the source as it was copied
 * (snapshot and checksum), the destination companies, every dependency and applicability decision (plan), what was
 * created (result) and how it ended. Append-only: a row is never deleted, and only its outcome is ever written after it
 * starts. The same manifest is in the audit trail (CLONE event + the operation's summary).
 */
#[Fillable(['tenant_id', 'ulid', 'idempotency_key', 'adapter', 'mode', 'source_id', 'source_label', 'source_company_ids', 'source_checksum', 'source_snapshot',
    'destination_company_ids', 'plan', 'result', 'status', 'error', 'audit_operation_id', 'initiated_by', 'started_at', 'completed_at'])]
class ConfigurationCloneOperation extends Model
{
    use BelongsToTenant;

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const REFUSED = 'refused';

    public const STATUSES = [self::RUNNING => 'Running', self::COMPLETED => 'Completed', self::FAILED => 'Failed', self::REFUSED => 'Refused'];

    /** What may still be written once an attempt has started: its outcome. */
    private const OUTCOME = ['idempotency_key', 'result', 'status', 'error', 'audit_operation_id', 'completed_at', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $operation): void {
            if (array_diff(array_keys($operation->getDirty()), self::OUTCOME) !== []) {
                throw new ConfigurationException('A clone record is append-only: only its outcome is written after it starts.');
            }
        });
        static::deleting(fn () => throw new ConfigurationException('A clone record is append-only and is never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'source_company_ids' => 'array',
            'source_snapshot' => 'array',
            'destination_company_ids' => 'array',
            'plan' => 'array',
            'result' => 'array',
            'source_id' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function lineage(): HasMany
    {
        return $this->hasMany(ConfigurationLineage::class, 'operation_id');
    }

    public function adapterLabel(): string
    {
        return app(CloneRegistry::class)->label($this->adapter);
    }

    /** @return list<string> the destination companies' names (ids of companies since removed are shown as #id) */
    public function destinationNames(): array
    {
        $ids = array_map('intval', (array) $this->destination_company_ids);
        // Named even when outside the viewer's organisation scope: the history row already says which companies took part.
        $names = Company::query()->withoutGlobalScope(AccessScope::class)->whereKey($ids)->pluck('name', 'id');

        return array_map(fn (int $id) => (string) ($names[$id] ?? "#{$id}"), $ids);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }
}
