<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'person_id', 'employer', 'designation', 'from_date', 'to_date', 'location', 'responsibilities', 'reason_for_leaving', 'is_verified'])]
class PersonExperience extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'is_verified' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return $this->employer;
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
