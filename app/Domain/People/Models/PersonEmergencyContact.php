<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\People\Support\PhoneNumbers;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'person_id', 'name', 'relation', 'phone_country_code', 'phone', 'alternate_phone', 'email', 'priority'])]
class PersonEmergencyContact extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        // HROne parity MS-4: the phone in E.164 when it has a country code; as typed without one (as before). The alternate
        // phone is kept as typed.
        static::saving(function (self $contact): void {
            PhoneNumbers::normaliseOn($contact, 'phone', 'phone_country_code');
        });
    }

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
