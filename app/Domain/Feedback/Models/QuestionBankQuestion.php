<?php

namespace App\Domain\Feedback\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Feedback\Support\FeedbackQuestions;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question of a question bank (HROne `questionTitle`, `questionType` "Response type", `options` / `optionTitle`,
 * `rating`, `questBankIsMandatory`). Checked like a template question when saved.
 */
#[Fillable(['tenant_id', 'question_bank_id', 'title', 'type', 'options', 'scale_max', 'is_mandatory', 'sort_order', 'status'])]
class QuestionBankQuestion extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'is_mandatory' => true, 'sort_order' => 0];

    protected static function booted(): void
    {
        static::saving(function (self $question): void {
            $checked = FeedbackQuestions::normalise([['title' => $question->title, 'type' => $question->type, 'options' => $question->options, 'scale_max' => $question->scale_max, 'mandatory' => $question->is_mandatory]])[0];
            $question->title = $checked['title'];
            $question->options = $checked['options'];
            $question->scale_max = $checked['scale_max'];
        });
    }

    protected function casts(): array
    {
        return ['options' => 'array', 'scale_max' => 'integer', 'is_mandatory' => 'boolean', 'sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        return 'Bank question '.mb_strimwidth((string) $this->title, 0, 60, '…');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    /** The question as a template carries it. @return array<string, mixed> */
    public function asTemplateQuestion(): array
    {
        return ['title' => $this->title, 'type' => $this->type, 'options' => $this->options, 'scale_max' => $this->scale_max, 'mandatory' => (bool) $this->is_mandatory, 'bank_question_id' => $this->id];
    }
}
