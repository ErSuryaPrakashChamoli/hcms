<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable rating scale (§35): ordered levels with value, label and description. HROne setup parity HP-8c: a
 * level may carry a range (from / to); when any level has one, a rating takes the label of the range containing it.
 */
#[Fillable(['tenant_id', 'name', 'code', 'levels', 'is_default', 'status'])]
class RatingScale extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $scale): void {
            $scale->code = strtoupper(trim((string) $scale->code));
            $scale->levels = self::normaliseLevels((array) ($scale->levels ?? []));

            // Phase 7: a scale used by a scheduled, active or closed cycle keeps its levels (its
            // reviews are pinned); publish a new scale instead.
            // HP-8c: the same holds for a scale a launched cycle rates competencies on.
            if ($scale->exists && $scale->isDirty('levels') && PerformanceCycle::query()->where(fn ($q) => $q->where('rating_scale_id', $scale->getKey())->orWhere('competency_rating_scale_id', $scale->getKey()))->where('status', '!=', 'draft')->exists()) {
                throw new \RuntimeException('This rating scale is used by a launched cycle; create a new scale instead of changing its levels.');
            }

            if ($scale->is_default) {
                static::query()->whereKeyNot($scale->getKey())->update(['is_default' => false]);
            }
        });
    }

    protected function casts(): array
    {
        return ['levels' => 'array', 'is_default' => 'boolean', 'status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    /**
     * Levels sorted by value. HP-8c: optional from / to (both or neither) are kept; from ≤ to, ranges never overlap.
     *
     * @return list<array<string, mixed>>
     */
    public static function normaliseLevels(array $levels): array
    {
        $rows = collect($levels)->map(function ($l) {
            $row = ['value' => (float) $l['value'], 'label' => $l['label'], 'description' => $l['description'] ?? null];
            $from = $l['from'] ?? null;
            $to = $l['to'] ?? null;
            $hasFrom = $from !== null && $from !== '';
            $hasTo = $to !== null && $to !== '';
            if ($hasFrom !== $hasTo) {
                throw new \RuntimeException("Level {$row['label']}: give both ends of its range, or neither.");
            }
            if ($hasFrom) {
                if ((float) $from > (float) $to) {
                    throw new \RuntimeException("Level {$row['label']}: the range starts after it ends.");
                }
                $row += ['from' => (float) $from, 'to' => (float) $to];
            }

            return $row;
        })->sortBy('value')->values();

        $ranged = $rows->filter(fn ($l) => isset($l['from']))->sortBy('from')->values();
        foreach ($ranged as $i => $level) {
            $next = $ranged[$i + 1] ?? null;
            if ($next !== null && $next['from'] <= $level['to']) {
                throw new \RuntimeException("The ranges of {$level['label']} and {$next['label']} overlap.");
            }
        }

        return $rows->all();
    }

    public function hasRanges(): bool
    {
        return collect($this->levels)->contains(fn ($l) => isset($l['from'], $l['to']));
    }

    public function min(): float
    {
        return (float) (collect($this->levels)->min('value') ?? 1);
    }

    public function max(): float
    {
        return (float) (collect($this->levels)->max('value') ?? 5);
    }

    /**
     * Label of a rating. HP-8c: the level whose range contains it, when the scale has ranges. Otherwise (and for a
     * rating outside every range) the level found by the rounding rule (HP-8b performance.rating.rounding): round =
     * the nearest level value (as before), floor = the highest level at or below it, ceil = the lowest at or above it.
     */
    public function labelFor(?float $rating, string $rounding = 'round'): ?string
    {
        if ($rating === null) {
            return null;
        }
        $levels = collect($this->levels);
        $ranged = $levels->first(fn ($l) => isset($l['from'], $l['to']) && $rating >= (float) $l['from'] && $rating <= (float) $l['to']);
        if ($ranged !== null) {
            return $ranged['label'];
        }

        $sorted = $levels->sortBy('value')->values();
        $level = match ($rounding) {
            'floor' => $sorted->filter(fn ($l) => (float) $l['value'] <= $rating + 1e-9)->last() ?? $sorted->first(),
            'ceil' => $sorted->first(fn ($l) => (float) $l['value'] >= $rating - 1e-9) ?? $sorted->last(),
            default => $levels->sortBy(fn ($l) => abs($l['value'] - $rating))->first(),
        };

        return $level['label'] ?? null;
    }

    /** @return array<int|string, string> value => "value · label" */
    public function options(): array
    {
        return collect($this->levels)->mapWithKeys(fn ($l) => [(string) $l['value'] => rtrim(rtrim(number_format($l['value'], 1, '.', ''), '0'), '.').' · '.$l['label']])->all();
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first() ?? static::query()->where('status', 'active')->first();
    }
}
