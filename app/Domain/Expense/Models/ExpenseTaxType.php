<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Expense\Support\MasterCode;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity EX-A (Expense → Tax): the tax heads an employee splits a receipt's tax into. Tenant data, unrelated
 * to the platform's own billing tax engine. The default rate is only a suggestion an administrator may enter; the
 * receipt carries the tax actually charged. PeopleOS never ships a rate.
 */
#[Fillable(['tenant_id', 'name', 'code', 'default_rate', 'sort_order', 'status'])]
class ExpenseTaxType extends Model
{
    use Auditable, BelongsToTenant, MasterCode;

    protected $attributes = ['status' => 'active', 'sort_order' => 0];

    protected function casts(): array
    {
        return ['default_rate' => 'decimal:2', 'sort_order' => 'integer', 'status' => ActiveStatus::class];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
