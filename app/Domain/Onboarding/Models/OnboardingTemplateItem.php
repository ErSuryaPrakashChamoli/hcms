<?php

namespace App\Domain\Onboarding\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Models\Form;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'onboarding_template_id', 'phase', 'type', 'title', 'description', 'owner_type', 'owner_role_id', 'owner_user_id', 'document_type_id', 'form_id', 'due_offset_days', 'is_mandatory', 'sort_order'])]
class OnboardingTemplateItem extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'due_offset_days' => 'integer',
            'is_mandatory' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'onboarding';
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class, 'onboarding_template_id');
    }

    public function ownerRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'owner_role_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
