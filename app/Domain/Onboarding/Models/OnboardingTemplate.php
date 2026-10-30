<?php

namespace App\Domain\Onboarding\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable onboarding checklist (§21): which template applies is a rule, like policies. HROne setup parity 08.2: a
 * template of kind induction is an induction programme (its own plan next to the onboarding checklist), and a template
 * may start N days before joining (preboarding items fall due then; blank = as before, when the person enters preboarding).
 */
#[Fillable(['tenant_id', 'name', 'key', 'kind', 'starts_days_before_joining', 'description', 'priority', 'conditions', 'status'])]
class OnboardingTemplate extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['kind' => 'onboarding'];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'priority' => 'integer',
            'starts_days_before_joining' => 'integer',
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
