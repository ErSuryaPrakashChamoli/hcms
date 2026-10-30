<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne setup parity HP-9f (Happiness index → reason tags): a reason employees may pick with their mood. At most
 * MAX_ACTIVE are active at a time ("You can add up to 20 active tags"); HROne's fourteen are seeded (MoodDefaults). A
 * reason someone picked is made inactive, never deleted, so past check-ins keep their meaning.
 */
#[Fillable(['tenant_id', 'name', 'status', 'is_system', 'sort_order'])]
class MoodReason extends Model
{
    use Auditable, BelongsToTenant;

    public const MAX_ACTIVE = 20;

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    protected $attributes = ['status' => 'active', 'is_system' => false, 'sort_order' => 0];

    protected static function booted(): void
    {
        static::saving(function (self $reason): void {
            $reason->name = trim(preg_replace('/\s+/', ' ', strip_tags((string) $reason->name)));
            if ($reason->name === '' || mb_strlen($reason->name) > 60) {
                throw new RuntimeException('A reason needs a name of at most 60 characters.');
            }
            if (! array_key_exists((string) $reason->status, self::STATUSES)) {
                throw new RuntimeException('Unknown reason status.');
            }
            $becomesActive = $reason->status === 'active' && (! $reason->exists || $reason->getRawOriginal('status') !== 'active');
            if ($becomesActive && self::query()->where('status', 'active')->whereKeyNot($reason->getKey() ?? 0)->count() >= self::MAX_ACTIVE) {
                throw new RuntimeException('You can have up to '.self::MAX_ACTIVE.' active reasons; make one inactive first.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Mood reasons are made inactive, never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return "Mood reason {$this->name}";
    }
}
