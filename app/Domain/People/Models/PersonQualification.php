<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'person_id', 'qualification', 'specialisation', 'institution', 'board_or_university', 'year_of_completion', 'grade_or_score', 'is_verified'])]
class PersonQualification extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'year_of_completion' => 'integer',
            'is_verified' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return $this->qualification;
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
