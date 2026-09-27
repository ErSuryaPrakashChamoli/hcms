<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A saved report definition (§84): dataset + fields + filters + grouping + calculated fields + visualization. */
#[Fillable(['tenant_id', 'name', 'dataset', 'description', 'definition', 'is_shared', 'owner_id', 'status'])]
class Report extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'is_shared' => false];

    protected function casts(): array
    {
        return ['definition' => 'array', 'is_shared' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'analytics';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class)->latest('started_at');
    }

    public function def(string $key, mixed $default = null): mixed
    {
        return data_get($this->definition, $key, $default);
    }
}
