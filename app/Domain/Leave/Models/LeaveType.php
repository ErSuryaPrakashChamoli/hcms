<?php

namespace App\Domain\Leave\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A kind of leave (§25). Entitlements live on the leave policy, not here. */
#[Fillable(['tenant_id', 'name', 'description', 'code', 'category', 'unit', 'min_request_units', 'max_request_units', 'is_paid', 'allow_half_day', 'is_encashable', 'requires_document', 'requires_approval', 'cancellation_policy', 'applicable_gender', 'colour', 'sort_order', 'status', 'effective_from', 'effective_to'])]
class LeaveType extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['unit' => 'days', 'requires_approval' => true, 'requires_document' => false, 'cancellation_policy' => 'self'];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'allow_half_day' => 'boolean',
            'is_encashable' => 'boolean',
            'requires_document' => 'boolean',
            'requires_approval' => 'boolean',
            'min_request_units' => 'float',
            'max_request_units' => 'float',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'sort_order' => 'integer',
            'status' => ActiveStatus::class,
        ];
    }

    public function auditModule(): string
    {
        return 'leave';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
