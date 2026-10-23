<?php

namespace App\Domain\Letters\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Letter factory template (§40) with {{ employee.name }}-style variables (LetterVariables). Two formats: html (written
 * in the rich-text editor) and docx (the client's own Word file, uploaded; its text is kept in body for search and
 * checking). Templates converted from the first markdown editor are html.
 */
#[Fillable(['tenant_id', 'name', 'code', 'type', 'format', 'subject', 'body', 'file_disk', 'file_path', 'file_name', 'requires_approval', 'letter_signatory_id', 'version', 'status'])]
class LetterTemplate extends Model
{
    use Auditable, BelongsToTenant;

    public const HTML = 'html';

    public const DOCX = 'docx';

    protected $attributes = ['status' => 'active', 'version' => 1, 'format' => self::HTML];

    protected static function booted(): void
    {
        static::saving(function (self $t): void {
            $t->code = strtoupper(trim((string) $t->code));
            $t->body ??= '';
            if ($t->exists && ($t->isDirty('body') || $t->isDirty('subject') || $t->isDirty('file_path') || $t->isDirty('format'))) {
                $t->version = ($t->getRawOriginal('version') ?? 1) + 1;
            }
        });
    }

    protected function casts(): array
    {
        return ['requires_approval' => 'boolean', 'version' => 'integer', 'status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'letters';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function signatory(): BelongsTo
    {
        return $this->belongsTo(LetterSignatory::class, 'letter_signatory_id');
    }

    public function isDocx(): bool
    {
        return $this->format === self::DOCX;
    }
}
