<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A configurable rating scale (§35): ordered levels with value, label and description. */
#[Fillable(['tenant_id', 'name', 'code', 'levels', 'is_default', 'status'])]
class RatingScale extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $scale): void {
            $scale->code = strtoupper(trim((string) $scale->code));
            $scale->levels = collect($scale->levels ?? [])->map(fn ($l) => ['value' => (float) $l['value'], 'label' => $l['label'], 'description' => $l['description'] ?? null])->sortBy('value')->values()->all();

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

    public function min(): float
    {
        return (float) (collect($this->levels)->min('value') ?? 1);
    }

    public function max(): float
    {
        return (float) (collect($this->levels)->max('value') ?? 5);
    }

    /** Label of the level whose value is nearest to the rating. */
    public function labelFor(?float $rating): ?string
    {
        if ($rating === null) {
            return null;
        }

        return collect($this->levels)->sortBy(fn ($l) => abs($l['value'] - $rating))->first()['label'] ?? null;
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
