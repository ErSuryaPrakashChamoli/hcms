<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Issued on completion of a course with validity (§37). valid → expiring → expired / revoked. */
#[Fillable(['tenant_id', 'employee_id', 'course_id', 'course_version_id', 'learning_program_version_id', 'learning_enrolment_id', 'learning_completion_id', 'number', 'issued_on', 'expires_on', 'recertification_due_on', 'renewed_by_certificate_id', 'score', 'issuer', 'credential_url', 'verification_code', 'verification_status', 'document_path', 'document_name', 'document_sha256', 'is_external', 'status', 'revoked_at', 'revoked_by', 'revocation_reason'])]
class LearningCertificate extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['valid' => 'Valid', 'expiring' => 'Expiring', 'expired' => 'Expired', 'revoked' => 'Revoked'];

    /** Only these may change after issue — never the dates, number, holder or learning. */
    public const MUTABLE = ['status', 'revoked_at', 'revoked_by', 'revocation_reason', 'renewed_by_certificate_id', 'verification_status', 'document_path', 'document_name', 'document_sha256', 'updated_at'];

    protected $attributes = ['status' => 'valid'];

    protected $hidden = ['document_path', 'verification_code'];

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->verification_code ??= \Illuminate\Support\Str::random(40));
        static::updating(function (self $c) {
            if ($c->getRawOriginal('status') === 'revoked') {
                throw new \RuntimeException('A revoked certificate is read-only.');
            }
            if (array_diff(array_keys($c->getDirty()), self::MUTABLE) !== []) {
                throw new \RuntimeException('An issued certificate cannot change its holder, learning, number or dates — expired credentials are renewed by recertification, never extended.');
            }
            if ($c->isDirty('document_path') && $c->getRawOriginal('document_path') !== null) {
                throw new \RuntimeException('A certificate document is never replaced.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Certificates are revoked, never deleted.'));
    }

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date', 'recertification_due_on' => 'date', 'score' => 'decimal:2', 'is_external' => 'boolean', 'revoked_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return "Certificate {$this->number}";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function auditSensitiveAttributes(): array
    {
        return ['verification_code', 'document_path'];
    }

    public function courseVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class);
    }

    public function completion(): BelongsTo
    {
        return $this->belongsTo(LearningCompletion::class, 'learning_completion_id');
    }

    public function isCurrent(): bool
    {
        return in_array($this->status, ['valid', 'expiring'], true);
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(LearningEnrolment::class, 'learning_enrolment_id');
    }
}
