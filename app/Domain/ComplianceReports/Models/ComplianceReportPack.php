<?php

namespace App\Domain\ComplianceReports\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every compliance report of a company and period generated in one go (see ComplianceReports). Visible within the
 * company scope; the stored file holds what its requester could see, so only the requester downloads it.
 */
#[Fillable(['tenant_id', 'company_id', 'frequency', 'basis', 'period_key', 'period_start', 'period_end', 'options', 'status', 'report_count', 'empty_count', 'manifest', 'metadata', 'disk', 'path', 'size_bytes', 'sha256', 'requested_by', 'scope_fingerprint', 'started_at', 'completed_at', 'reviewed_by', 'reviewed_at', 'review_note', 'error'])]
class ComplianceReportPack extends Model
{
    use Auditable, BelongsToTenant, ScopedByOrganisation;

    public const STATUSES = ['queued' => 'Queued', 'running' => 'Generating', 'completed' => 'Ready', 'failed' => 'Failed'];

    public string $accessScopeDimension = 'compliance_report_pack';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'options' => 'array',
            'manifest' => 'array',
            'metadata' => 'array',
            'reviewed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function auditLabel(): string
    {
        return 'Compliance reports '.$this->period()->label();
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return ['manifest', 'metadata', 'scope_fingerprint'];
    }

    public function period(): ReportPeriod
    {
        return ReportPeriod::fromKey($this->period_key);
    }

    public function hasFile(): bool
    {
        return $this->status === 'completed' && $this->path !== null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
