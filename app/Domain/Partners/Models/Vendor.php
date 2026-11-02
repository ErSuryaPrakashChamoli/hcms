<?php

namespace App\Domain\Partners\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Services\OptionLists;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne parity WO-15 (setup matrix 01 §1.33, HROne Setup → Vendor): a supplier, agency or consultant of the organisation.
 * Written only through App\Domain\Partners\Services\Vendors (validation, audit reason). The PAN is encrypted at rest and
 * hidden; its last four characters are kept apart for display. Never deleted: a vendor no longer used is inactivated.
 */
#[Fillable(['tenant_id', 'code', 'name', 'kind', 'gstin', 'pan', 'contact_name', 'email', 'phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country', 'status'])]
#[Hidden(['pan'])]
class Vendor extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    protected $attributes = ['status' => 'active', 'country' => 'IN'];

    protected static function booted(): void
    {
        static::saving(function (Vendor $vendor): void {
            if ($vendor->isDirty('pan')) {
                $pan = $vendor->pan;
                $vendor->pan_last4 = filled($pan) ? substr((string) $pan, -4) : null;
            }
        });
    }

    protected function casts(): array
    {
        return ['pan' => 'encrypted'];
    }

    public function auditModule(): string
    {
        return 'partners';
    }

    public function auditLabel(): string
    {
        return 'Vendor '.$this->code.' '.$this->name;
    }

    public function auditSensitiveAttributes(): array
    {
        return [...config('peopleos.audit.sensitive_attributes', []), 'pan'];
    }

    public function businessPartners(): HasMany
    {
        return $this->hasMany(BusinessPartner::class, 'vendor_id');
    }

    public function isActive(): bool
    {
        return ($this->getAttributes()['status'] ?? 'active') === 'active';
    }

    /** "ABCDE1234F" shown as "••••••234F"; null when no PAN is on file. */
    public function maskedPan(): ?string
    {
        $last4 = $this->getAttributes()['pan_last4'] ?? null;

        return $last4 === null ? null : str_repeat('•', 6).$last4;
    }

    public function kindLabel(): ?string
    {
        return app(OptionLists::class)->label('vendor_kinds', $this->getAttributes()['kind'] ?? null);
    }
}
