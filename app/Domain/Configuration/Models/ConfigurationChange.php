<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Enums\ChangeStatus;
use App\Domain\Configuration\Enums\RiskLevel;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One governed configuration change (§71–§75). */
#[Fillable(['tenant_id', 'subject_type', 'subject_id', 'subject_label', 'change_type', 'risk_level', 'status', 'before', 'payload', 'impact', 'effective_from', 'reason', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note', 'published_at', 'rolled_back_by_change_id'])]
class ConfigurationChange extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'payload' => 'array',
            'impact' => 'array',
            'risk_level' => RiskLevel::class,
            'status' => ChangeStatus::class,
            'effective_from' => 'date',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        return "Change #{$this->id}: ".class_basename($this->subject_type).' '.($this->subject_label ?? "#{$this->subject_id}");
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function rolledBackBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rolled_back_by_change_id');
    }

    /** @return array<string, array{before: mixed, after: mixed}> */
    public function diff(): array
    {
        $diff = [];

        foreach ($this->payload as $field => $after) {
            $before = $this->before[$field] ?? null;

            if ($before != $after) {
                $diff[$field] = ['before' => $before, 'after' => $after];
            }
        }

        return $diff;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [ChangeStatus::PendingApproval, ChangeStatus::Scheduled], true);
    }
}
