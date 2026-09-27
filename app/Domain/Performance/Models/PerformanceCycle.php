<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An appraisal cycle (§35): period, rating scale, ordered stages with windows, weights for goals vs
 * competencies, the competencies rated, and eligibility. draft → active (launched) → closed.
 */
#[Fillable(['tenant_id', 'name', 'code', 'type', 'period_start', 'period_end', 'rating_scale_id', 'stages', 'weights', 'settings', 'competency_ids', 'eligibility', 'status', 'current_stage', 'launched_at', 'closed_at'])]
class PerformanceCycle extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'draft'];

    public const STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'closed' => 'Closed'];

    protected static function booted(): void
    {
        static::saving(function (self $cycle): void {
            $cycle->code = strtoupper(trim((string) $cycle->code));

            if ($cycle->exists && $cycle->getRawOriginal('status') !== 'draft') {
                foreach (['period_start', 'period_end', 'rating_scale_id', 'weights', 'competency_ids'] as $locked) {
                    if ($cycle->isDirty($locked)) {
                        throw new \RuntimeException('A launched cycle keeps its period, scale, weights and competencies. Create a new cycle instead.');
                    }
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'stages' => 'array',
            'weights' => 'array',
            'settings' => 'array',
            'competency_ids' => 'array',
            'eligibility' => 'array',
            'launched_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(RatingScale::class, 'rating_scale_id');
    }

    public function appraisals(): HasMany
    {
        return $this->hasMany(Appraisal::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /** @return array<int, string> ordered stage keys */
    public function stageKeys(): array
    {
        return array_values(array_column($this->stages ?? [], 'key'));
    }

    public function hasStage(string $key): bool
    {
        return in_array($key, $this->stageKeys(), true);
    }

    public function nextStage(): ?string
    {
        $keys = $this->stageKeys();
        $index = $this->current_stage === null ? -1 : array_search($this->current_stage, $keys, true);

        return $keys[$index + 1] ?? null;
    }

    public function stageIndex(?string $key): int
    {
        $index = array_search($key, $this->stageKeys(), true);

        return $index === false ? -1 : $index;
    }

    public function isAtOrPast(string $key): bool
    {
        return $this->stageIndex($this->current_stage) >= $this->stageIndex($key) && $this->hasStage($key);
    }

    public function weight(string $key): float
    {
        return (float) ($this->weights[$key] ?? config("peopleos.performance.default_weights.{$key}", 0));
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function competencies()
    {
        return Competency::query()->whereIn('id', $this->competency_ids ?? [])->orderBy('name')->get();
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /** Default stage windows spread across the month after the period ends. */
    public static function defaultStages(CarbonInterface $periodEnd): array
    {
        $labels = config('peopleos.performance.stages');
        $keys = ['goal_setting', 'self_review', 'manager_review', 'calibration', 'final'];
        $start = $periodEnd->copy()->addDay();
        $stages = [];

        foreach ($keys as $i => $key) {
            $stages[] = ['key' => $key, 'name' => $labels[$key], 'starts_on' => $start->copy()->addDays($i * 7)->toDateString(), 'ends_on' => $start->copy()->addDays($i * 7 + 6)->toDateString()];
        }

        return $stages;
    }
}
