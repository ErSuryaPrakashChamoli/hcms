<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a cloned record came from (configuration cloning): one row per record a clone created (role root for the copy
 * itself, dependency for its rules), reused or mapped, with the source's checksum at the time. Lineage only records; it
 * never links behaviour: changing a source changes nothing in its copies. Append-only.
 */
#[Fillable(['tenant_id', 'operation_id', 'adapter', 'source_id', 'target_id', 'destination_company_id', 'role', 'action', 'source_checksum'])]
class ConfigurationLineage extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'configuration_lineage';

    public const ROOT = 'root';

    public const DEPENDENCY = 'dependency';

    public const CLONED = 'cloned';

    public const REUSED = 'reused';

    public const MAPPED = 'mapped';

    protected static function booted(): void
    {
        static::updating(fn () => throw new ConfigurationException('Clone lineage is append-only.'));
        static::deleting(fn () => throw new ConfigurationException('Clone lineage is append-only.'));
    }

    protected function casts(): array
    {
        return ['source_id' => 'integer', 'target_id' => 'integer', 'destination_company_id' => 'integer'];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(ConfigurationCloneOperation::class, 'operation_id');
    }

    public function destinationCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'destination_company_id');
    }
}
