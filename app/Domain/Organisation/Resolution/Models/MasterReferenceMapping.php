<?php

namespace App\Domain\Organisation\Resolution\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A value typed in an import file that an authorised person confirmed means one existing master record
 * (docs/architecture/name-resolution.md). Written only through ReferenceMappings; retired, never deleted.
 */
#[Fillable(['tenant_id', 'master', 'company_id', 'value', 'value_key', 'record_id', 'scope', 'status', 'active_key', 'reason', 'created_by', 'retired_by', 'retired_at'])]
class MasterReferenceMapping extends Model
{
    use Auditable, BelongsToTenant;

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'record_id' => 'integer', 'retired_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'organisation';
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function remembered(): bool
    {
        return $this->scope === null;
    }

    /** master|company|scope|sha1(value): at most 191 characters, so nothing is ever truncated into a collision. */
    public static function activeKey(string $master, ?int $companyId, string $valueKey, ?string $scope): string
    {
        return $master.'|'.($companyId ?? '*').'|'.($scope ?? '*').'|'.sha1($valueKey);
    }
}
