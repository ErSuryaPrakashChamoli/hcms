<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tenant_id', 'name', 'key', 'description', 'requires_approval', 'status'])]
class Form extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'status' => ActiveStatus::class,
        ];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class)->orderByDesc('version');
    }

    public function draft(): HasOne
    {
        return $this->hasOne(FormVersion::class)->where('status', VersionStatus::Draft);
    }

    public function published(): HasOne
    {
        return $this->hasOne(FormVersion::class)->where('status', VersionStatus::Published);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class)->latest();
    }
}
