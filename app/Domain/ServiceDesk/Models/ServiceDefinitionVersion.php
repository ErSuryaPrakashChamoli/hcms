<?php

namespace App\Domain\ServiceDesk\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Models\FormVersion;
use App\Domain\Identity\Models\User;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Phase 12: one effective-dated version of a catalogue service — its form (a published Configuration
 * Form version), field classification, availability (employee / manager / HR), applicable lifecycle
 * states, organisation scope and eligibility rule, attachment rule, approval (workflow key), domain
 * action, SLA policy, assignment, confidentiality and visibility.
 *
 * The Phase 11 configuration lifecycle, unchanged: Draft → Pending approval → Approved, which is
 * Scheduled (later date) or Active (in force) → Superseded, or Archived. Only a draft is edited; from
 * submission the content is frozen; once approved only the end date (closed once) and the status
 * move. A correction is a new version. Open requests stay pinned to the version they were raised under.
 */
#[Fillable([
    'tenant_id', 'service_definition_id', 'version', 'status', 'effective_from', 'effective_to', 'description', 'form_version_id', 'field_security',
    'availability', 'lifecycle_states', 'org_scope', 'eligibility', 'attachment_rule', 'approval_required', 'workflow_key', 'domain_action', 'sla_policy_id',
    'default_priority', 'assignment', 'confidentiality', 'visible_to_employee', 'manager_visible', 'listed', 'change_note', 'checksum', 'prepared_by',
    'submitted_at', 'approved_by', 'approved_at', 'decision_note', 'archived_at', 'lock_version',
])]
class ServiceDefinitionVersion extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    public const STATUSES = ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'scheduled' => 'Approved — scheduled', 'active' => 'Active', 'superseded' => 'Superseded', 'archived' => 'Archived'];

    /** Approved versions: part of the service's effective-dated history. */
    public const APPROVED = ['scheduled', 'active', 'superseded'];

    public const CONTENT = [
        'service_definition_id', 'version', 'effective_from', 'description', 'form_version_id', 'field_security', 'availability', 'lifecycle_states', 'org_scope',
        'eligibility', 'attachment_rule', 'approval_required', 'workflow_key', 'domain_action', 'sla_policy_id', 'default_priority', 'assignment',
        'confidentiality', 'visible_to_employee', 'manager_visible', 'listed', 'change_note',
    ];

    protected $attributes = ['status' => 'draft', 'attachment_rule' => 'optional', 'approval_required' => false, 'default_priority' => 'normal', 'confidentiality' => 'standard', 'visible_to_employee' => true, 'manager_visible' => false, 'listed' => true, 'lock_version' => 0];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            $was = $version->getRawOriginal('status');
            $content = array_intersect(array_keys($version->getDirty()), self::CONTENT);
            if ($content !== [] && $was !== 'draft') {
                throw new RuntimeException('Only a draft service version is edited; a correction is a new version ('.implode(', ', $content).').');
            }
            if ($version->isDirty('effective_to') && in_array($was, self::APPROVED, true) && $version->getRawOriginal('effective_to') !== null) {
                throw new RuntimeException('An approved service version is closed only once.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Service versions are never deleted; archive a draft instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date', 'field_security' => 'array', 'availability' => 'array',
            'lifecycle_states' => 'array', 'org_scope' => 'array', 'eligibility' => 'array', 'assignment' => 'array', 'approval_required' => 'boolean',
            'visible_to_employee' => 'boolean', 'manager_visible' => 'boolean', 'listed' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
            'archived_at' => 'datetime', 'lock_version' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'servicedesk';
    }

    public function auditLabel(): string
    {
        return ($this->service?->code ?? 'Service').' v'.$this->version;
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceDefinition::class, 'service_definition_id');
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(ServiceSlaPolicy::class, 'sla_policy_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return in_array($this->status, self::APPROVED, true);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Who may raise it: employee (for themselves), manager (for a report), hr (on someone's behalf). */
    public function availableTo(string $audience): bool
    {
        return (bool) (($this->availability ?? ['employee' => true, 'manager' => false, 'hr' => true])[$audience] ?? false);
    }

    /** The classification of one form field: standard | sensitive | restricted. */
    public function fieldClass(string $key): string
    {
        return (string) data_get($this->field_security, "{$key}.class", 'standard');
    }

    public function fieldVisibleToEmployee(string $key): bool
    {
        return (bool) data_get($this->field_security, "{$key}.employee_visible", true);
    }
}
