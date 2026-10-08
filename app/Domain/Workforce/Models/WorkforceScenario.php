<?php

namespace App\Domain\Workforce\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 10: a configurable planning scenario (Baseline, Growth, Expansion … named by the tenant) with
 * explicit, labelled planning assumptions — never predictions about people. Draft is editable;
 * approved is locked (only archived afterwards). A scenario never touches live workforce data.
 */
#[Fillable(['tenant_id', 'code', 'name', 'description', 'assumptions', 'status', 'created_by', 'approved_by', 'approved_at', 'lock_version'])]
class WorkforceScenario extends Model
{
    use Auditable, BelongsToTenant;

    public const TRANSITIONS = ['draft' => ['approved', 'archived'], 'approved' => ['archived'], 'archived' => []];

    protected $attributes = ['status' => 'draft'];

    protected static function booted(): void
    {
        static::saving(function (self $s) {
            $s->code = strtoupper(trim((string) $s->code));
            foreach (array_keys((array) $s->assumptions) as $key) {
                if (! array_key_exists($key, config('peopleos.workforce.scenario_assumptions'))) {
                    throw new \RuntimeException("Unknown planning assumption '{$key}'.");
                }
            }
            $rate = $s->assumptions['attrition_rate_percent'] ?? null;
            if ($rate !== null && ($rate < 0 || $rate > 100)) {
                throw new \RuntimeException('The attrition assumption is a percentage between 0 and 100.');
            }
        });
        static::updating(function (self $s) {
            $from = $s->getRawOriginal('status');
            if ($s->isDirty('status') && ! in_array($s->status, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \RuntimeException("A scenario cannot move from {$from} to {$s->status}.");
            }
            if ($from !== 'draft' && array_diff(array_keys($s->getDirty()), ['status', 'lock_version', 'updated_at']) !== []) {
                throw new \RuntimeException('An approved scenario is locked; create a new scenario instead.');
            }
            if (! $s->isDirty('lock_version')) {
                $s->lock_version = (int) $s->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Scenarios are archived, never deleted.'));
    }

    protected function casts(): array
    {
        return ['assumptions' => 'array', 'approved_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'workforce';
    }

    public function planVersions(): HasMany
    {
        return $this->hasMany(WorkforcePlanVersion::class);
    }
}
