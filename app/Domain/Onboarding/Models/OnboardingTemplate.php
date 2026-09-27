<?php

namespace App\Domain\Onboarding\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A configurable onboarding checklist (§21): which template applies is a rule, like policies. */
#[Fillable(['tenant_id', 'name', 'key', 'description', 'priority', 'conditions', 'status'])]
class OnboardingTemplate extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'priority' => 'integer',
            'status' => ActiveStatus::class,
        ];
    }

    public function auditModule(): string
    {
        return 'onboarding';
    }

    public function items(): HasMany
    {
        return $this->hasMany(OnboardingTemplateItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(OnboardingPlan::class);
    }
}
