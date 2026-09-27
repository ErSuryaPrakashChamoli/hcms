<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tenant_id', 'type', 'name', 'code', 'description', 'status'])]
class Policy extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
        ];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function typeLabel(): string
    {
        return config("peopleos.policies.types.{$this->type}.label", $this->type);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class)->orderByDesc('version');
    }

    public function draft(): HasOne
    {
        return $this->hasOne(PolicyVersion::class)->where('status', VersionStatus::Draft);
    }

    public function assignmentRules(): HasMany
    {
        return $this->hasMany(PolicyAssignmentRule::class);
    }

    /** The published version effective on a date, if any. */
    public function versionEffectiveOn(CarbonInterface|string|null $date = null): ?PolicyVersion
    {
        return $this->versions()->where('status', VersionStatus::Published)->effectiveOn($date)->first();
    }
}
