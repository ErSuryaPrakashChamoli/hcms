<?php

namespace App\Domain\Billing\Models;

use App\Domain\Tax\Enums\TaxIdType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SaaS.7: one version of a Markedge selling entity's identity (platform catalogue): legal name, address,
 * jurisdiction and tax registration from a date. Rows never change; invoices copy the version they were issued
 * under, so a later change never alters an issued invoice. A version is pending until a second operator approves it
 * (the model refuses an approval by its maker); only approved versions apply.
 */
#[Fillable(['entity_code', 'version', 'legal_name', 'address_line1', 'address_line2', 'city', 'postal_code', 'country', 'subdivision',
    'tax_id_type', 'tax_id_value', 'registrations', 'status', 'approval_id', 'approved_by', 'approved_at', 'effective_from', 'reason', 'created_by'])]
class SupplierProfile extends Model
{
    protected $table = 'billing_supplier_profiles';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    protected static function booted(): void
    {
        static::updating(function (self $profile): void {
            // Only a pending version moves: linked to its approval, then approved, rejected or withdrawn once. Its content never changes.
            $pending = $profile->getRawOriginal('status') === self::PENDING;
            if (! $pending || array_diff(array_keys($profile->getDirty()), ['status', 'approval_id', 'approved_by', 'approved_at', 'updated_at']) !== []
                || ($profile->isDirty('status') && ! in_array($profile->status, [self::APPROVED, self::REJECTED, self::WITHDRAWN], true))
                || ($profile->status === self::APPROVED && ((int) $profile->approved_by === 0 || (int) $profile->approved_by === (int) $profile->created_by))) {
                throw new RuntimeException('A supplier profile version never changes: propose a new version (another operator approves it).');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Supplier profiles are never deleted: invoices refer to them.');
        });
    }

    protected function casts(): array
    {
        return ['tax_id_type' => TaxIdType::class, 'effective_from' => 'date', 'version' => 'integer', 'registrations' => 'array', 'approved_at' => 'datetime'];
    }

    /** @return array<string, mixed> what an invoice freezes */
    public function snapshot(): array
    {
        return ['profile_id' => $this->id, 'entity' => $this->entity_code, 'version' => $this->version, 'legal_name' => $this->legal_name,
            'address' => array_values(array_filter([$this->address_line1, $this->address_line2, trim("{$this->city} {$this->postal_code}")])),
            'country' => $this->country, 'subdivision' => $this->subdivision, 'tax_id_type' => $this->tax_id_type?->value, 'tax_id' => $this->tax_id_value,
            'registrations' => $this->registrations ?? []];
    }
}
