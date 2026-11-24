<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Configuration\Applicability\ApplicabilityMode;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The companies one configuration record was explicitly made to apply to (docs/architecture/configuration-applicability.md):
 * the mode chosen (every company, selected companies, one company), whether companies created later join, and the
 * effective-dated company rows. Written only through EntityApplicability; a record without a row keeps its earlier reach.
 */
#[Fillable(['tenant_id', 'configuration_type', 'configuration_id', 'mode', 'include_future', 'created_by', 'updated_by'])]
class ConfigurationApplicability extends Model
{
    use BelongsToTenant;

    protected $table = 'configuration_applicability';

    protected function casts(): array
    {
        return ['mode' => ApplicabilityMode::class, 'include_future' => 'boolean', 'configuration_id' => 'integer'];
    }

    public function companies(): HasMany
    {
        return $this->hasMany(ConfigurationApplicabilityCompany::class, 'applicability_id');
    }
}
