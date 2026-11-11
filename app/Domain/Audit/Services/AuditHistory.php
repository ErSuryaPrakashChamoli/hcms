<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads a record's own history back from the audit trail: what a field held before it last took a value. Used when a
 * change is given back and the record keeps no copy of its earlier state (Streamline S2: a reopened payroll month
 * returns a paid expense report to the approval it was paid on).
 */
final class AuditHistory
{
    public function valueBefore(Model $model, string $field, string $after): ?string
    {
        $change = AuditEventChange::query()->where('field', $field)->where('after', $after)
            ->whereIn('audit_event_id', AuditEvent::query()->where('entity_type', $model::class)->where('entity_id', (string) $model->getKey())->select('id'))
            ->orderByDesc('id')->first(['id', 'before']);

        return $change?->before;
    }
}
