<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'person_id', 'name', 'relation', 'phone', 'alternate_phone', 'email', 'priority'])]
class PersonEmergencyContact extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
        ];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
