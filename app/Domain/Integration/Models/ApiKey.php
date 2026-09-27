<?php

namespace App\Domain\Integration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Integration credential. The secret is shown once at creation; only its hash is kept. */
#[Fillable(['tenant_id', 'name', 'prefix', 'secret_hash', 'scopes', 'last_used_at', 'expires_at', 'status', 'created_by'])]
#[Hidden(['secret_hash'])]
class ApiKey extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'status' => ActiveStatus::class,
        ];
    }

    public function auditModule(): string
    {
        return 'integration';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->prefix})";
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'secret_hash', 'last_used_at'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true) || in_array('*', $this->scopes ?? [], true);
    }

    public function isUsable(): bool
    {
        return $this->status === ActiveStatus::Active && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
