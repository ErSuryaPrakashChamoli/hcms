<?php

namespace App\Domain\Integration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data import DI-1 (Integration → JV mappings): the accounting (journal voucher) code of one PeopleOS record in one
 * dimension, for every accounting system (no system) or one system. Many records may share a code. Written only through
 * JvCodes (the JV codes screen and the JV mapping uploads).
 */
#[Fillable(['tenant_id', 'dimension', 'record_type', 'record_id', 'integration_system_id', 'code'])]
class JvCode extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        // The unique key (dimension, record, system) also holds without a system: '' stands for "every system".
        static::saving(function (JvCode $jv): void {
            $jv->system_key = (string) ($jv->getAttributes()['integration_system_id'] ?? '');
        });
    }

    protected function casts(): array
    {
        return ['record_id' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'integration';
    }

    public function auditLabel(): string
    {
        return "{$this->dimension}:{$this->code}";
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(IntegrationSystem::class, 'integration_system_id');
    }
}
