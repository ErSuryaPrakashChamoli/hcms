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

/**
 * One physical or licensed item (§38). Status moves only through the Assets service so every
 * change leaves a movement row: in_stock → assigned → (in_repair | in_transit) → … → retired / disposed / lost.
 */
#[Fillable(['tenant_id', 'asset_category_id', 'asset_model_id', 'company_id', 'location_id', 'asset_tag', 'name', 'serial_number', 'status', 'condition', 'custodian_id', 'purchase_date', 'purchase_cost', 'vendor', 'invoice_number', 'warranty_until', 'notes'])]
class Asset extends Model
{
    use Auditable, BelongsToTenant, HasCustomFields;

    protected $attributes = ['status' => 'in_stock', 'condition' => 'good'];

    protected static function booted(): void
    {
        static::saving(fn (self $a) => $a->asset_tag = strtoupper(trim((string) $a->asset_tag)));
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
