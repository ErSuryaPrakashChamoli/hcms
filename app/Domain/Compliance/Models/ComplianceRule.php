<?php

namespace App\Domain\Compliance\Models;

use App\Support\EffectiveDating\HasEffectiveDates;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A versioned statutory rule (§32). Platform-owned: no tenant_id, read-only for tenants, written
 * only by `peopleos:compliance:sync` from database/data/compliance/*.php.
 */
#[Fillable(['jurisdiction', 'code', 'state', 'name', 'version', 'effective_from', 'effective_to', 'parameters', 'source', 'status'])]
class ComplianceRule extends Model
{
    use HasEffectiveDates;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'parameters' => 'array',
        ];
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return data_get($this->parameters, $key, $default);
    }

    public function label(): string
    {
        return $this->name.' v'.$this->version;
    }
}
