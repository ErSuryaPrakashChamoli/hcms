<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SkillFactory::class)]
#[Fillable(['tenant_id', 'name', 'code', 'category', 'skill_type', 'description', 'skill_scale_id', 'status', 'effective_from', 'effective_to'])]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** Phase 8: the proficiency scale this skill is measured on. */
    public function scale(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Skills\Models\SkillScale::class, 'skill_scale_id');
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
