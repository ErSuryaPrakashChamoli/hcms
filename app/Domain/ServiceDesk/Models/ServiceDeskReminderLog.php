<?php

namespace App\Domain\ServiceDesk\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12: one reminder or escalation per kind, subject and deterministic bucket (e.g. the SLA due
 * time, the escalation level, the waiting-day count). The unique key is the idempotency guard: a
 * second scheduler run, a retried job or a concurrent worker cannot send the same reminder twice.
 * Derived de-duplication rows.
 */
#[Fillable(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'bucket'])]
class ServiceDeskReminderLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;
}
