<?php

namespace App\Domain\Assets\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Concerns\HasCustomFields;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * One physical or licensed item (§38). Status moves only through the Assets service so every
 * change leaves a movement row: in_stock → assigned → (in_repair | in_transit) → … → retired / disposed / lost.
 */
#[Fillable(['tenant_id', 'asset_category_id', 'asset_subtype_id', 'asset_model_id', 'company_id', 'location_id', 'asset_tag', 'name', 'serial_number', 'status', 'condition', 'custodian_id', 'purchase_date', 'purchase_cost', 'vendor', 'invoice_number', 'warranty_until', 'notes'])]
class Asset extends Model
{
    use Auditable, BelongsToTenant, HasCustomFields;

    protected $attributes = ['status' => 'in_stock', 'condition' => 'good'];

    protected static function booted(): void
    {
        static::saving(function (self $a): void {
            $a->asset_tag = strtoupper(trim((string) $a->asset_tag));
            self::checkTaxonomy($a);
        });
    }

    /**
     * HROne setup parity AS-A: the sub type follows the product when not chosen, must belong to the asset type, and a sub
     * type that does not allow duplicate serial numbers refuses a serial another of its assets already carries.
     */
    private static function checkTaxonomy(self $asset): void
    {
        $attributes = $asset->getAttributes();
        if (($attributes['asset_subtype_id'] ?? null) === null && ($attributes['asset_model_id'] ?? null) !== null) {
            $fromModel = AssetModel::query()->whereKey($attributes['asset_model_id'])->value('asset_subtype_id');
            if ($fromModel !== null) {
                $asset->asset_subtype_id = (int) $fromModel;
            }
        }
        $subtypeId = $asset->getAttributes()['asset_subtype_id'] ?? null;
        if ($subtypeId === null) {
            return;
        }
        $subtype = AssetSubtype::query()->find($subtypeId) ?? throw new RuntimeException('Unknown asset sub type.');
        if ((int) $subtype->asset_category_id !== (int) $asset->asset_category_id) {
            throw new RuntimeException("The sub type {$subtype->name} belongs to another asset type.");
        }
        $serial = trim((string) ($attributes['serial_number'] ?? ''));
        if ($subtype->allow_duplicate_serial || $serial === '' || ! $asset->isDirty(['serial_number', 'asset_subtype_id', 'asset_model_id'])) {
            return;
        }
        $clash = self::query()->where('asset_subtype_id', $subtype->id)->whereRaw('LOWER(TRIM(serial_number)) = ?', [mb_strtolower($serial)])
            ->when($asset->exists, fn ($q) => $q->whereKeyNot($asset->getKey()))->value('asset_tag');
        if ($clash !== null) {
            throw new RuntimeException("Serial number {$serial} is already used by asset {$clash}; the sub type {$subtype->name} does not allow duplicate serial numbers.");
        }
    }

    protected function casts(): array
    {
        return ['purchase_date' => 'date', 'purchase_cost' => 'decimal:2', 'warranty_until' => 'date'];
    }

    public function auditModule(): string
    {
        return 'assets';
    }

    public function auditLabel(): string
    {
        return "{$this->name} [{$this->asset_tag}]";
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class, 'asset_model_id');
    }

    public function subtype(): BelongsTo
    {
        return $this->belongsTo(AssetSubtype::class, 'asset_subtype_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'custodian_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->latest('assigned_on')->latest('id');
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(AssetAssignment::class)->where('status', 'active')->latestOfMany();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AssetMovement::class)->latest('occurred_at')->latest('id');
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(AssetRepair::class)->latest('sent_on');
    }

    public function disposal(): HasOne
    {
        return $this->hasOne(AssetDisposal::class);
    }

    public function isAssignable(): bool
    {
        return $this->status === 'in_stock';
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, ['retired', 'disposed', 'lost'], true);
    }
}
