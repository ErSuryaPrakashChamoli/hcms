<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable(['tenant_id', 'name', 'code', 'category', 'requires_expiry', 'mandatory_for_onboarding', 'sample_path', 'sample_instructions', 'employee_can_view', 'employee_can_upload', 'mandatory_for_employee', 'expiry_alert_days', 'linked_field', 'required_with_field', 'status'])]
class DocumentType extends Model
{
    use Auditable, BelongsToTenant;

    /** HROne setup parity CA-4: defaults keep today's behaviour (employees see their documents; only HR uploads). */
    protected $attributes = ['employee_can_view' => true, 'employee_can_upload' => false, 'mandatory_for_employee' => false, 'required_with_field' => false];

    protected static function booted(): void
    {
        static::saving(function (DocumentType $type): void {
            $field = $type->getAttributes()['linked_field'] ?? null;
            if ($field !== null && ! array_key_exists($field, (array) config('peopleos.documents.linked_fields', []))) {
                throw new \RuntimeException('Unknown profile field for a document type.');
            }
            if ($field === null) {
                $type->required_with_field = false;
            }
        });
        // A replaced sample file is removed from storage (only this organisation's sample folder).
        static::updated(function (DocumentType $type): void {
            $old = $type->getRawOriginal('sample_path');
            if ($type->wasChanged('sample_path') && filled($old) && str_starts_with((string) $old, self::sampleDirectory().'/')) {
                Storage::disk(config('peopleos.documents.disk', 'local'))->delete((string) $old);
            }
        });
    }

    /** Where document type samples are kept on the documents disk (private; served through the authorised file route). */
    public static function sampleDirectory(): string
    {
        return 'tenants/'.app(TenantContext::class)->id().'/document-types';
    }

    protected function casts(): array
    {
        return [
            'requires_expiry' => 'boolean',
            'mandatory_for_onboarding' => 'boolean',
            'employee_can_view' => 'boolean',
            'employee_can_upload' => 'boolean',
            'mandatory_for_employee' => 'boolean',
            'expiry_alert_days' => 'integer',
            'required_with_field' => 'boolean',
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
