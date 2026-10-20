<?php

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Records CREATE / UPDATE / DELETE / RESTORE audit events with field-level diffs.
 *
 * @mixin Model
 */
trait Auditable
{
    protected ?string $auditReason = null;

    protected ?string $auditApprovalReference = null;

    protected bool $auditingDisabled = false;

    protected ?AuditAction $auditActionOverride = null;

    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->recordAudit(AuditAction::Create));
        static::updated(fn (Model $model) => $model->recordAudit(AuditAction::Update));
        static::deleted(fn (Model $model) => $model->recordAudit(AuditAction::Delete));
        static::registerModelEvent('restored', fn (Model $model) => $model->recordAudit(AuditAction::Restore));
    }

    /** The "What changed?" history of this record (blueprint §104). */
    public function auditEvents(): HasMany
    {
        // A model that moved namespace lists its former class names (auditEntityAliases) so audit rows
        // written before the move stay in its history; stored rows are never rewritten (hash chain).
        $types = [static::class, ...(method_exists($this, 'auditEntityAliases') ? $this->auditEntityAliases() : [])];

        return $this->hasMany(AuditEvent::class, 'entity_id')
            ->whereIn('entity_type', $types)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    public function withAuditReason(?string $reason, ?string $approvalReference = null): static
    {
        $this->auditReason = $reason;
        $this->auditApprovalReference = $approvalReference;

        return $this;
    }

    /** Record the next create/update under a domain action (e.g. STATUTORY_REGISTRATION_CREATED) with the same field diff. */
    public function withAuditAction(?AuditAction $action): static
    {
        $this->auditActionOverride = $action;

        return $this;
    }

    public function withoutAuditing(): static
    {
        $this->auditingDisabled = true;

        return $this;
    }

    /** Module name used in audit events; derived from App\Domain\{Module}\... by default. */
    public function auditModule(): string
    {
        if (preg_match('/^App\\\\Domain\\\\([A-Za-z]+)\\\\/', static::class, $m)) {
            return Str::snake($m[1]);
        }

        return Str::snake(class_basename(static::class));
    }

    /** @return list<string> */
    public function auditSensitiveAttributes(): array
    {
        return config('peopleos.audit.sensitive_attributes', []);
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return config('peopleos.audit.ignored_attributes', []);
    }

    public function auditEffectiveDate(): ?string
    {
        if (! array_key_exists('effective_from', $this->getAttributes())) {
            return null;
        }

        $value = $this->getAttribute('effective_from');

        return $value ? $this->asDateTime($value)->toDateString() : null;
    }

    protected function recordAudit(AuditAction $action): void
    {
        if ($this->auditingDisabled) {
            return;
        }

        $changes = $this->auditChangesFor($action);

        if ($action === AuditAction::Update && $changes === []) {
            return;
        }

        app(AuditRecorder::class)->record(
            action: $this->auditActionOverride ?? $action,
            module: $this->auditModule(),
            entity: $this,
            changes: $changes,
            reason: $this->auditReason,
            approvalReference: $this->auditApprovalReference,
            effectiveDate: $this->auditEffectiveDate(),
        );

        $this->auditReason = null;
        $this->auditApprovalReference = null;
        $this->auditActionOverride = null;
    }

    /**
     * @return array<int, array{field: string, before: mixed, after: mixed, sensitive: bool}>
     */
    protected function auditChangesFor(AuditAction $action): array
    {
        $excluded = array_flip($this->auditExcludedAttributes());
        $sensitive = array_flip($this->auditSensitiveAttributes());

        $attributes = match ($action) {
            AuditAction::Create => array_map(fn ($after) => [null, $after], $this->getAttributes()),
            AuditAction::Update => collect($this->getChanges())
                ->map(fn ($after, $key) => [$this->getRawOriginal($key), $after])
                ->all(),
            AuditAction::Delete => array_map(fn ($before) => [$before, null], $this->getAttributes()),
            default => [],
        };

        $changes = [];

        foreach ($attributes as $field => [$before, $after]) {
            if (isset($excluded[$field]) || $before === $after) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'before' => $before,
                'after' => $after,
                'sensitive' => isset($sensitive[$field]),
            ];
        }

        return $changes;
    }
}
