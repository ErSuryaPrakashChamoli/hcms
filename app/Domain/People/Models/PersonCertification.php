<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'person_id', 'name', 'issuing_body', 'credential_id', 'issued_on', 'expires_on', 'is_verified'])]
class PersonCertification extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
            'is_verified' => 'boolean',
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
