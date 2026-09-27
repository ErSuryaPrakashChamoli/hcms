<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'person_id', 'name', 'relation', 'date_of_birth', 'gender', 'is_dependent', 'is_nominee', 'nominee_share', 'phone'])]
class PersonFamilyMember extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_dependent' => 'boolean',
            'is_nominee' => 'boolean',
            'nominee_share' => 'decimal:2',
        ];
    }

    public function auditLabel(): string
    {
        return $this->name.' ('.$this->relation.')';
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
