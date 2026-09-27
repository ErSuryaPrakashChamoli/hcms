<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'person_id', 'skill_id', 'proficiency', 'years_of_experience', 'last_used_on'])]
class PersonSkill extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'years_of_experience' => 'integer',
            'last_used_on' => 'date',
        ];
    }

    public function auditLabel(): string
    {
        return ($this->relationLoaded('skill') ? $this->skill?->name : $this->skill()->value('name')).' ('.$this->proficiency.')';
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
