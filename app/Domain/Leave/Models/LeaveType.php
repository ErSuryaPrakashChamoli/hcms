<?php

namespace App\Domain\Leave\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A kind of leave (§25). Entitlements live on the leave policy, not here. */
#[Fillable(['tenant_id', 'name', 'code', 'category', 'is_paid', 'allow_half_day', 'is_encashable', 'applicable_gender', 'colour', 'sort_order', 'status'])]
class LeaveType extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'allow_half_day' => 'boolean',
            'is_encashable' => 'boolean',
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
