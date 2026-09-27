<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'name', 'code', 'category', 'requires_expiry', 'mandatory_for_onboarding', 'status'])]
class DocumentType extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'requires_expiry' => 'boolean',
            'mandatory_for_onboarding' => 'boolean',
            'status' => ActiveStatus::class,
        ];
    }

    public function auditModule(): string
    {
        return 'documents';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function isSensitive(): bool
    {
        return in_array($this->category, config('peopleos.documents.sensitive_categories', []), true);
    }
}
