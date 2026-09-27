<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'person_id', 'type', 'address_line_1', 'address_line_2', 'city', 'state_code', 'postal_code', 'country_code', 'effective_from', 'effective_to'])]
class PersonAddress extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function auditLabel(): string
    {
        return ucfirst($this->type).' address';
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
