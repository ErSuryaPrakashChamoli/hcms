<?php

namespace App\Domain\Feedback\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * HROne setup parity P13 (Forms & templates → Feedback → Question bank): a named set of reusable questions that feedback
 * templates copy from. Banks and questions are made inactive, never deleted.
 */
#[Fillable(['tenant_id', 'code', 'name', 'module', 'description', 'status'])]
class QuestionBank extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    protected $attributes = ['status' => 'active', 'module' => 'workforce'];

    protected static function booted(): void
    {
        static::saving(function (self $bank): void {
            $bank->code = strtoupper(trim((string) $bank->code));
            if (! preg_match('/^[A-Z0-9_-]{1,32}$/', $bank->code)) {
                throw new RuntimeException('A question bank code is up to 32 letters, digits, dashes or underscores.');
            }
            if (! array_key_exists((string) $bank->module, (array) config('peopleos.feedback.modules')) || ! array_key_exists((string) $bank->status, self::STATUSES)) {
                throw new RuntimeException('Unknown module or status.');
            }
        });
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        return 'Question bank '.$this->name;
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuestionBankQuestion::class)->orderBy('sort_order')->orderBy('id');
    }
}
