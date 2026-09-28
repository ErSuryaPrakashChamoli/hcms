<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * Lifecycle header of one statutory output (Phase 5 Parts O–Q): EPF ECR, ESI, PT, LWF or a TDS
 * quarterly statement. Content (entries, totals, rule versions) is frozen once approved; later
 * steps only record export, filing and reconciliation facts. EXPORTED never implies SUBMITTED:
 * SUBMITTED and ACKNOWLEDGED are recorded by a person with an external reference.
 */
#[Fillable(['tenant_id', 'company_id', 'legal_entity_id', 'establishment_id', 'return_type', 'form_code', 'legacy_form_code', 'return_kind', 'state_code', 'period_key', 'period_start', 'period_end', 'sequence', 'parent_return_id', 'status', 'uniqueness_key', 'rule_versions', 'payroll_run_ids', 'format_code', 'format_version', 'format_verification_status', 'export_layout_id', 'totals', 'validation', 'blocking_count', 'warning_count', 'reconciliation_status', 'attestations', 'generated_by', 'generated_at', 'validated_at', 'approved_by', 'approved_at', 'exported_by', 'exported_at', 'export_filename', 'export_path', 'export_checksum', 'local_validation', 'locally_validated_at', 'portal_validation_result', 'portal_validation_reference', 'portal_validated_at', 'portal_validated_by', 'submitted_by', 'submitted_at', 'external_reference', 'acknowledged_by', 'acknowledged_at', 'acknowledgement_reference', 'reconciled_at', 'cancelled_at', 'reason', 'operation_id'])]
class StatutoryReturn extends Model
{
    use Auditable, BelongsToTenant, ScopedByOrganisation;

    public const DRAFT = 'draft';

    public const CALCULATED = 'calculated';

    public const VALIDATED = 'validated';

    public const APPROVED = 'approved';

    public const EXPORTED = 'exported';

    public const SUBMITTED = 'submitted';

    public const ACKNOWLEDGED = 'acknowledged';

    public const RECONCILIATION_REQUIRED = 'reconciliation_required';

    public const RECONCILED = 'reconciled';

    public const REVISED = 'revised';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::CALCULATED, self::VALIDATED, self::APPROVED, self::EXPORTED, self::SUBMITTED, self::ACKNOWLEDGED, self::RECONCILIATION_REQUIRED, self::RECONCILED, self::REVISED, self::CANCELLED];

    /** Content may change only in these statuses (before approval). */
    public const EDITABLE = [self::DRAFT, self::CALCULATED, self::VALIDATED, self::RECONCILIATION_REQUIRED];

    /** Fields that form the approved content. */
    public const CONTENT = ['company_id', 'legal_entity_id', 'establishment_id', 'return_type', 'form_code', 'return_kind', 'state_code', 'period_key', 'period_start', 'period_end', 'sequence', 'parent_return_id', 'rule_versions', 'payroll_run_ids', 'totals', 'validation', 'blocking_count', 'warning_count', 'generated_by', 'generated_at'];

    public string $accessScopeDimension = 'statutory_return';

    protected static function booted(): void
    {
        static::updating(function (StatutoryReturn $return) {
            $original = $return->getOriginal('status');
            $changed = array_intersect(array_keys($return->getDirty()), self::CONTENT);

            if (! in_array($original, self::EDITABLE, true) && $changed !== []) {
                throw new RuntimeException("A {$original} return is immutable (".implode(', ', $changed).'); create a revision instead.');
            }
        });

        static::deleting(fn () => throw new RuntimeException('Statutory returns are never deleted; cancel them instead.'));
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'rule_versions' => 'array',
            'payroll_run_ids' => 'array',
            'totals' => 'array',
            'validation' => 'array',
            'attestations' => 'array',
            'local_validation' => 'array',
            'locally_validated_at' => 'datetime',
            'portal_validated_at' => 'datetime',
            'generated_at' => 'datetime',
            'validated_at' => 'datetime',
            'approved_at' => 'datetime',
            'exported_at' => 'datetime',
            'submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function label(): string
    {
        return "{$this->form_code} {$this->period_key}".($this->return_kind !== 'regular' ? " ({$this->return_kind} #{$this->sequence})" : '');
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function auditLabel(): string
    {
        return $this->label();
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_return_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_return_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(StatutoryReturnAction::class)->orderBy('id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(StatutoryReconciliation::class)->orderBy('id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(StatutorySnapshot::class);
    }

    public function exportLayout(): BelongsTo
    {
        return $this->belongsTo(StatutoryExportLayout::class, 'export_layout_id');
    }

    public function epfRun(): HasOne
    {
        return $this->hasOne(EpfReturnRun::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
