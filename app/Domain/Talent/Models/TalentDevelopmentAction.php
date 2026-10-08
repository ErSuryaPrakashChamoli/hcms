<?php

namespace App\Domain\Talent\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Development\Models\DevelopmentPlanItem;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Succession\Models\Successor;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 9: links a successor or talent-review decision to a Phase 8 development-plan item (no parallel development or learning records). */
#[Fillable(['tenant_id', 'employee_id', 'successor_id', 'talent_review_item_id', 'development_plan_item_id', 'action_type', 'created_by'])]
class TalentDevelopmentAction extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected static function booted(): void
    {
        static::saving(function (self $a) {
            if (! array_key_exists($a->action_type, config('peopleos.talent.development_action_types'))) {
                throw new \RuntimeException("Unknown development action type '{$a->action_type}'.");
            }
        });
        static::updating(fn () => throw new \RuntimeException('Development action links are immutable.'));
        static::deleting(fn () => throw new \RuntimeException('Development action links are never deleted.'));
    }

    public function auditModule(): string
    {
        return 'talent';
    }

    public function auditLabel(): string
    {
        return 'Development action ('.$this->action_type.')';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(DevelopmentPlanItem::class, 'development_plan_item_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(Successor::class);
    }
}
