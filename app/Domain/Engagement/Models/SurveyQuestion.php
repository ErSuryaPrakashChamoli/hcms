<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Phase 13: a question pinned to one survey version. Employees see only employeeView(); the
 * administrator metadata, scoring configuration and analysis tags never leave the server for them.
 * Frozen with its version.
 */
#[Fillable(['tenant_id', 'survey_version_id', 'key', 'position', 'type', 'prompt', 'help', 'required', 'options', 'scale', 'admin_metadata', 'scoring', 'analysis_tags'])]
class SurveyQuestion extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        $guard = function (self $q): void {
            $status = SurveyVersion::query()->whereKey($q->survey_version_id)->value('status');
            if ($status !== null && $status !== 'draft') {
                throw new RuntimeException('Questions of a submitted survey version never change; create a new version.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['position' => 'integer', 'required' => 'boolean', 'options' => 'array', 'scale' => 'array', 'admin_metadata' => 'array', 'scoring' => 'array', 'analysis_tags' => 'array'];
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return "Question {$this->key}";
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'survey_version_id');
    }

    /** @return array<string, string> option value => label (choices, likert, yes/no, rating) */
    public function choiceOptions(): array
    {
        return match ($this->type) {
            'yes_no' => ['yes' => 'Yes', 'no' => 'No'],
            'likert' => config('peopleos.engagement.likert_options'),
            'rating' => collect(range((int) ($this->scale['min'] ?? 1), (int) ($this->scale['max'] ?? 5)))->mapWithKeys(fn ($n) => [(string) $n => (string) $n])->all(),
            'single_choice', 'multiple_choice' => collect($this->options ?? [])->mapWithKeys(fn ($o, $k) => is_array($o) ? [(string) ($o['value'] ?? $k) => (string) ($o['label'] ?? $o['value'] ?? $k)] : [(string) (is_int($k) ? $o : $k) => (string) $o])->all(),
            default => [],
        };
    }

    /** What an employee may see of the question (no metadata, scoring or analysis tags). */
    public function employeeView(): array
    {
        return ['key' => $this->key, 'type' => $this->type, 'prompt' => $this->prompt, 'help' => $this->help, 'required' => $this->required, 'options' => $this->choiceOptions(),
            'scale' => in_array($this->type, ['rating'], true) ? ['min' => (int) ($this->scale['min'] ?? 1), 'max' => (int) ($this->scale['max'] ?? 5), 'labels' => $this->scale['labels'] ?? null] : null];
    }

    /** Numeric answers that can be averaged meaningfully. */
    public function isNumeric(): bool
    {
        return in_array($this->type, ['rating', 'likert', 'number'], true);
    }
}
