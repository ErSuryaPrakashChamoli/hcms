<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Identity\Models\User;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Configuration versioning (§69): published settings are never overwritten. */
#[Fillable(['tenant_id', 'policy_id', 'version', 'settings', 'status', 'effective_from', 'effective_to', 'published_by', 'published_at', 'change_note'])]
class PolicyVersion extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getRawOriginal('status') !== VersionStatus::Draft->value && $version->isDirty('settings')) {
                throw new ConfigurationException('Published policy versions are immutable; create a new version.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'version' => 'integer',
            'status' => VersionStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        $policy = $this->relationLoaded('policy') ? $this->policy : $this->policy()->first();

        return ($policy?->name ?? 'Policy')." v{$this->version}";
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
