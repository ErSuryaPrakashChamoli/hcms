<?php

namespace App\Domain\Bgv\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'bgv_case_id', 'type', 'status', 'result_notes', 'evidence_document_id', 'verified_by', 'completed_at'])]
class BgvCheck extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['pending' => 'Pending', 'in_progress' => 'In progress', 'clear' => 'Clear', 'discrepancy' => 'Discrepancy', 'failed' => 'Failed', 'skipped' => 'Skipped'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'bgv';
    }

    public function auditLabel(): string
    {
        return config("peopleos.bgv.check_types.{$this->type}", $this->type).' check';
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BgvCase::class, 'bgv_case_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'evidence_document_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['clear', 'discrepancy', 'failed', 'skipped'], true);
    }
}
