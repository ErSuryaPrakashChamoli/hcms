<?php

namespace App\Domain\Projects\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Geography\Countries;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Data import DI-1 (Core → Customers): an organisation projects are run for, with a contact and its tax registration
 * (GSTIN, VAT …). Not a business partner: business partners are individual contacts.
 */
#[Fillable(['tenant_id', 'code', 'name', 'contact_name', 'email', 'phone', 'address', 'country_code', 'tax_id', 'status'])]
class Customer extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            $country = $customer->getAttributes()['country_code'] ?? null;
            if ($country !== null && $country !== '') {
                if (! Countries::exists(strtoupper((string) $country))) {
                    throw new RuntimeException("'{$country}' is not a country: use its ISO 3166-1 two-letter code.");
                }
                $customer->country_code = strtoupper((string) $country);
            }
            $email = $customer->getAttributes()['email'] ?? null;
            if ($email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException("'{$email}' is not an e-mail address.");
            }
        });
    }

    protected function casts(): array
    {
        return ['status' => ActiveStatus::class];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
