<?php

namespace App\Domain\Assets\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne setup parity AS-B: an asset request — a new asset, a replacement of one the employee holds, or a return —
 * raised by the employee, their manager or HR, by the asset entitlement policy for a joiner, or by an exit. It may need
 * approval (asset.request.workflow_for: a workflow, else the Approval Center), then the asset type's owner role fulfils it
 * (assigns, replaces or takes back the asset) by its due date. Changes go through AssetRequests.
 */
#[Fillable([
    'tenant_id', 'number', 'employee_id', 'kind', 'source', 'source_key', 'asset_category_id', 'asset_subtype_id', 'asset_model_id', 'asset_id', 'asset_assignment_id',
    'fulfilled_asset_id', 'reason', 'needed_by', 'due_on', 'status', 'owner_role_id', 'requested_by', 'decided_at', 'decided_by', 'decision_note',
    'fulfilled_at', 'fulfilled_by', 'fulfilment_note', 'cancel_reason', 'cancelled_at',
])]
class AssetRequest extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const KINDS = ['new' => 'New asset', 'replacement' => 'Replacement', 'return' => 'Return'];

    public const SOURCES = ['employee' => 'Employee', 'manager' => 'Manager', 'hr' => 'HR', 'policy' => 'Joining (asset policy)', 'exit' => 'Exit'];

    public const STATUSES = ['requested' => 'Waiting for approval', 'approved' => 'To fulfil', 'rejected' => 'Rejected', 'fulfilled' => 'Fulfilled', 'cancelled' => 'Cancelled'];

    /** Requests still open (waiting for approval or fulfilment). */
    public const OPEN = ['requested', 'approved'];

    protected $attributes = ['status' => 'requested', 'source' => 'employee'];

    protected function casts(): array
    {
        return ['needed_by' => 'date', 'due_on' => 'date', 'decided_at' => 'datetime', 'fulfilled_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'assets';
    }

    public function auditLabel(): string
    {
        return 'Asset request '.($this->getAttributes()['number'] ?? '');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_on !== null && $this->due_on->lt(now()->startOfDay());
    }

    /** "Laptop · Premium series · Dell Vostro" */
    public function describe(): string
    {
        return implode(' · ', array_filter([$this->category?->name, $this->subtype?->name, $this->model?->name]));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function subtype(): BelongsTo
    {
        return $this->belongsTo(AssetSubtype::class, 'asset_subtype_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class, 'asset_model_id');
    }

    /** The asset to return or replace. */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fulfilledAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'fulfilled_asset_id');
    }

    public function ownerRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'owner_role_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function fulfiller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }
}
