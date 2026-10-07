<?php

namespace App\Domain\Billing\Models;

use App\Domain\Tax\Enums\CustomerType;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegistration;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.7: one version of a tenant's commercial billing identity: market, B2B/B2C, legal name, billing contact,
 * billing jurisdiction (country, subdivision) and tax registration. Separate from every HR record (employee PAN,
 * employer registrations). Rows never change; the version in force on a day is the latest started one, and an
 * invoice copies it at issue.
 */
#[Fillable(['version', 'effective_from', 'market_id', 'customer_type', 'legal_name', 'billing_email', 'billing_contact', 'address_line1',
    'address_line2', 'city', 'postal_code', 'country', 'subdivision', 'tax_registration', 'tax_id_type', 'tax_id_value', 'tax_id_status',
    'special_tax_status', 'reason', 'reference', 'created_by'])]
class TenantBillingProfile extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('A billing profile version never changes: record a new version.');
        });
        static::deleting(function (): void {
            throw new RuntimeException('Billing profiles are never deleted: invoices refer to them.');
        });
    }

    protected function casts(): array
    {
        return ['customer_type' => CustomerType::class, 'tax_registration' => TaxRegistration::class, 'tax_id_type' => TaxIdType::class,
            'effective_from' => 'date', 'version' => 'integer'];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(BillingMarket::class, 'market_id');
    }

    /** @return array<string, mixed> what an invoice freezes */
    public function snapshot(): array
    {
        return ['profile_id' => $this->id, 'version' => $this->version, 'customer_type' => $this->customer_type->value, 'legal_name' => $this->legal_name,
            'billing_email' => $this->billing_email, 'billing_contact' => $this->billing_contact,
            'address' => array_values(array_filter([$this->address_line1, $this->address_line2, trim("{$this->city} {$this->postal_code}")])),
            'country' => $this->country, 'subdivision' => $this->subdivision, 'tax_registration' => $this->tax_registration->value,
            'tax_id_type' => $this->tax_id_type?->value, 'tax_id' => $this->tax_id_value, 'tax_id_status' => $this->tax_id_status, 'special_tax_status' => $this->special_tax_status];
    }
}
