<?php

namespace App\Domain\Feedback\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Feedback\Support\FeedbackQuestions;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * HROne setup parity P13 (Forms & templates → Feedback templates): named questions for one process (peopleos.feedback.
 * processes: exit interviews by the employee, the manager and HR, confirmation, probation self-review, induction). A
 * feedback asked on it keeps a copy of the questions, so changing a template never changes answers already given.
 */
#[Fillable(['tenant_id', 'code', 'name', 'module', 'process', 'description', 'questions', 'status'])]
class FeedbackTemplate extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    protected $attributes = ['status' => 'active', 'module' => 'workforce'];

    protected static function booted(): void
    {
        static::saving(function (self $template): void {
            $template->code = strtoupper(trim((string) $template->code));
            if (! preg_match('/^[A-Z0-9_-]{1,32}$/', $template->code)) {
                throw new RuntimeException('A feedback template code is up to 32 letters, digits, dashes or underscores.');
            }
            $process = config('peopleos.feedback.processes.'.$template->process);
            if ($process === null || ! array_key_exists((string) $template->status, self::STATUSES)) {
                throw new RuntimeException('Unknown process or status.');
            }
            $template->module = $process['module'];
            $template->questions = FeedbackQuestions::normalise((array) ($template->questions ?? []));
            if ($template->questions === []) {
                throw new RuntimeException('A feedback template needs at least one question.');
            }
        });
    }

    protected function casts(): array
    {
        return ['questions' => 'array'];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        return 'Feedback template '.$this->name;
    }

    public function requests(): HasMany
    {
        return $this->hasMany(FeedbackRequest::class);
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function processLabel(): string
    {
        return (string) config("peopleos.feedback.processes.{$this->process}.label", $this->process);
    }
}
