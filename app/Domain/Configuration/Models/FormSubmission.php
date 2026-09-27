<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['tenant_id', 'form_id', 'form_version_id', 'subject_type', 'subject_id', 'submitted_by', 'data', 'status', 'reviewed_by', 'reviewed_at', 'review_note'])]
class FormSubmission extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        return "Submission #{$this->id}";
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
