<?php

namespace App\Domain\Recognition\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity HP-9d: the organisation's points budget lock row (one per tenant). Every posting against the budget
 * (deposits, cycle credits, badge points, lapses) locks it first, so two postings at once cannot both spend the last
 * points. It holds no balance: the budget is the sum of the ledger's budget rows.
 */
#[Fillable(['tenant_id'])]
class RewardPointBudget extends Model
{
    use Auditable, BelongsToTenant;

    public function auditModule(): string
    {
        return 'recognition';
    }

    public function auditLabel(): string
    {
        return 'Reward points budget';
    }
}
