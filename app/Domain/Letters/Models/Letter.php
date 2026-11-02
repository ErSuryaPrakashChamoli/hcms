<?php

namespace App\Domain\Letters\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A generated letter (§40): rendered content frozen at generation, approval, issue as an employee document. format is
 * markdown (letters from before the letter studio), html, or docx (rendered to file_path; body holds its text).
 *
 * HROne parity WO-13: up to four signatories (letter_signatory_id is the first), the process that drafted it (`process`,
 * a policy hook such as confirmation.letter_on_extend, or schedule), the letter it supersedes, and revocation (D-WO5 /
 * D-WO6): a revoked letter is hidden from the employee and kept, with its file, never deleted.
 */
#[Fillable(['tenant_id', 'number', 'employee_id', 'letter_template_id', 'letter_signatory_id', 'letter_signatory_2_id', 'letter_signatory_3_id', 'letter_signatory_4_id', 'type', 'format', 'subject', 'body', 'file_disk', 'file_path', 'context', 'status', 'requested_by', 'approved_by', 'approved_at', 'issued_by', 'issued_at', 'revoked_at', 'revoked_by', 'revoke_reason', 'document_id', 'review_note', 'source_type', 'source_id', 'process', 'supersedes_letter_id', 'letter_schedule_id'])]
class Letter extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['context' => 'array', 'approved_at' => 'datetime', 'issued_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'letters';
    }

    public function auditLabel(): string
    {
        return "{$this->number} {$this->subject}";
    }

    public function auditSensitiveAttributes(): array
    {
        return ['body', 'context'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LetterTemplate::class, 'letter_template_id');
    }

    public function signatory(): BelongsTo
    {
        return $this->belongsTo(LetterSignatory::class, 'letter_signatory_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'document_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isDocx(): bool
    {
        return $this->format === LetterTemplate::DOCX;
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    /** WO-13: the second, third and fourth signatories (the first is signatory()). */
    public function signatory2(): BelongsTo
    {
        return $this->belongsTo(LetterSignatory::class, 'letter_signatory_2_id');
    }

    public function signatory3(): BelongsTo
    {
        return $this->belongsTo(LetterSignatory::class, 'letter_signatory_3_id');
    }

    public function signatory4(): BelongsTo
    {
        return $this->belongsTo(LetterSignatory::class, 'letter_signatory_4_id');
    }

    /** @return list<int> the signatory ids in signing order (empty positions left out) */
    public function signatoryIds(): array
    {
        return array_values(array_map('intval', array_filter([$this->letter_signatory_id, $this->letter_signatory_2_id, $this->letter_signatory_3_id, $this->letter_signatory_4_id])));
    }

    /** WO-13 (D-WO6): the letter this one replaces. */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_letter_id');
    }

    /** WO-13: letters generated to replace this one (the newest last). */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_letter_id');
    }

    /** The replacement that is still in play (not rejected or revoked), if any. */
    public function replacement(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_letter_id')->ofMany(['id' => 'max'], fn ($query) => $query->whereNotIn('letters.status', ['rejected', 'revoked']));
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LetterSchedule::class, 'letter_schedule_id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(LetterEmail::class);
    }

    /** WO-13: the template or body used salary variables (salary.* or the older employee.ctc_*): a salary letter. */
    public function usesSalary(): bool
    {
        $context = (array) ($this->context ?? []);

        return ! empty($context['salary']) || isset($context['employee']['ctc_annual']) || isset($context['employee']['ctc_monthly']);
    }
}
