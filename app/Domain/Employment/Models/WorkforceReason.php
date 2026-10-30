<?php

namespace App\Domain\Employment\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A reason master (HROne setup parity, Workforce): separation, transfer and blacklist reasons offered on their requests.
 * CA-3 (Core HR → Separation reasons, Blacklist reason): a separation reason is voluntary or involuntary, offered to
 * employees resigning (for_employee) and / or to HR starting an exit (for_approver), and may require a remark from
 * either side; a blacklist reason is why an exited employee may not be rehired.
 */
#[Fillable(['tenant_id', 'kind', 'separation_kind', 'name', 'for_employee', 'for_approver', 'remark_required', 'approver_remark_required', 'status', 'sort_order'])]
class WorkforceReason extends Model
{
    use Auditable, BelongsToTenant;

    public const KINDS = ['separation' => 'Separation', 'transfer' => 'Transfer', 'blacklist' => 'Blacklist'];

    protected $attributes = ['status' => 'active', 'for_employee' => true, 'for_approver' => true, 'remark_required' => false, 'approver_remark_required' => false, 'sort_order' => 0];

    protected static function booted(): void
    {
        static::saving(function (WorkforceReason $reason): void {
            $kind = $reason->getAttributes()['separation_kind'] ?? null;
            if ($kind !== null && ($reason->kind !== 'separation' || ! array_key_exists($kind, (array) config('peopleos.people.separation_kinds', [])))) {
                throw new \RuntimeException('Only a separation reason is voluntary or involuntary.');
            }
        });
    }

    protected function casts(): array
    {
        return ['for_employee' => 'boolean', 'for_approver' => 'boolean', 'remark_required' => 'boolean', 'approver_remark_required' => 'boolean', 'sort_order' => 'integer'];
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind)->where('status', 'active')->orderBy('sort_order')->orderBy('name');
    }

    public function auditModule(): string
    {
        return 'employment';
    }

    public function auditLabel(): string
    {
        return (self::KINDS[$this->kind] ?? $this->kind).' reason '.$this->name;
    }
}
