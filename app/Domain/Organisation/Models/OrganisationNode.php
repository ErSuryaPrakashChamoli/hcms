<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['tenant_id', 'parent_id', 'nodeable_type', 'nodeable_id', 'sort_order', 'depth', 'path', 'status'])]
class OrganisationNode extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
            'sort_order' => 'integer',
            'depth' => 'integer',
        ];
    }

    public function auditLabel(): string
    {
        $unit = $this->relationLoaded('nodeable') ? $this->nodeable : $this->nodeable()->first();

        return sprintf('%s: %s', $this->typeLabel(), $unit?->name ?? "#{$this->nodeable_id}");
    }

    /** Path and depth are derived from parent_id; auditing parent_id alone tells the story. */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'path', 'depth'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function nodeable(): MorphTo
    {
        return $this->morphTo();
    }

    public function descendants(): Builder
    {
        return static::query()
            ->where('path', 'like', $this->path.'%')
            ->whereKeyNot($this->getKey());
    }

    public function typeKey(): string
    {
        return app(OrganisationTree::class)->typeKeyForClass($this->nodeable_type);
    }

    public function typeLabel(): string
    {
        return config("peopleos.organisation.node_types.{$this->typeKey()}.label", $this->typeKey());
    }

    public function isAncestorOf(self $other): bool
    {
        return $other->path !== null && $this->path !== null
            && $other->isNot($this)
            && str_starts_with($other->path, $this->path);
    }

    public static function pathFor(?self $parent, int $id): string
    {
        return ($parent?->path ?? '/').$id.'/';
    }
}
