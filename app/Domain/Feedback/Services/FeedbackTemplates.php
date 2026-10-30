<?php

namespace App\Domain\Feedback\Services;

use App\Domain\Configuration\Services\Blueprints;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Feedback\Models\QuestionBank;
use App\Domain\Feedback\Models\QuestionBankQuestion;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity P13 (Forms & templates → Feedback templates): the default template library ("Search template") is
 * the configuration pack `feedback-starter` (resources/packs, blueprint format: question banks and feedback templates).
 * It can be applied whole from Packs & Blueprints, or template by template here; adding never overwrites a template the
 * organisation already has with the same code.
 */
final class FeedbackTemplates
{
    public const PACK = 'feedback-starter';

    public function __construct(private readonly Blueprints $blueprints) {}

    /** @return array<string, array<string, mixed>> code => the library's template */
    public static function library(): array
    {
        $path = config('peopleos.configuration.packs_path').'/'.self::PACK.'.json';
        $pack = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        return collect($pack['feedback_templates'] ?? [])->keyBy('code')->all();
    }

    /**
     * Adds the chosen library templates the organisation does not have yet, with the library's question banks.
     *
     * @param  list<string>  $codes
     * @return int templates added
     */
    public function addFromLibrary(array $codes, User $actor): int
    {
        if (! $actor->hasPermission('feedback.manage')) {
            throw new RuntimeException('You cannot set up feedback templates.');
        }
        $library = self::library();
        $unknown = array_diff($codes, array_keys($library));
        if ($codes === [] || $unknown !== []) {
            throw new RuntimeException('Choose templates from the library.');
        }
        $existing = FeedbackTemplate::query()->whereIn('code', $codes)->pluck('code')->all();
        $new = array_values(array_diff($codes, $existing));
        if ($new === []) {
            return 0;
        }
        $path = config('peopleos.configuration.packs_path').'/'.self::PACK.'.json';
        $pack = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $banks = array_values(array_filter($pack['question_banks'] ?? [], fn (array $b) => ! QuestionBank::query()->where('code', $b['code'])->exists()));
        $this->blueprints->import(['format' => Blueprints::FORMAT, 'question_banks' => $banks, 'feedback_templates' => array_map(fn (string $c) => $library[$c], $new)], 'Added from the feedback template library');

        return count($new);
    }

    /**
     * Creates or changes a question bank and its questions in one audited step (questions removed from the list are
     * deleted: templates keep their own copies).
     *
     * @param  array<string, mixed>  $data  code, name, module, description, status
     * @param  list<array<string, mixed>>  $questions  [{id?, title, type, options, scale_max, is_mandatory, status}]
     */
    public function saveBank(?QuestionBank $bank, array $data, array $questions, ?string $reason, User $actor): QuestionBank
    {
        if (! $actor->hasPermission('feedback.manage')) {
            throw new RuntimeException('You cannot set up question banks.');
        }

        return DB::transaction(function () use ($bank, $data, $questions, $reason) {
            $bank ??= new QuestionBank;
            $bank->fill(array_intersect_key($data, array_flip(['code', 'name', 'module', 'description', 'status'])))->withAuditReason($reason)->save();
            $kept = [];
            foreach (array_values($questions) as $i => $row) {
                $question = filled($row['id'] ?? null) ? QuestionBankQuestion::query()->where('question_bank_id', $bank->id)->find($row['id']) : null;
                $question ??= new QuestionBankQuestion(['question_bank_id' => $bank->id]);
                $question->fill(['title' => $row['title'] ?? '', 'type' => $row['type'] ?? '', 'options' => $row['options'] ?? null, 'scale_max' => $row['scale_max'] ?? null,
                    'is_mandatory' => (bool) ($row['is_mandatory'] ?? true), 'sort_order' => $i + 1, 'status' => $row['status'] ?? 'active'])->withAuditReason($reason)->save();
                $kept[] = $question->id;
            }
            QuestionBankQuestion::query()->where('question_bank_id', $bank->id)->whereNotIn('id', $kept ?: [0])->get()->each(fn (QuestionBankQuestion $q) => $q->withAuditReason($reason)->delete());

            return $bank->refresh();
        });
    }

    /**
     * Creates or changes a feedback template (its questions are checked and tidied by the model). Feedback already asked
     * on it keeps the questions it was asked with.
     *
     * @param  array<string, mixed>  $data  code, name, process, description, questions, status
     */
    public function saveTemplate(?FeedbackTemplate $template, array $data, ?string $reason, User $actor): FeedbackTemplate
    {
        if (! $actor->hasPermission('feedback.manage')) {
            throw new RuntimeException('You cannot set up feedback templates.');
        }
        $template ??= new FeedbackTemplate;
        $template->fill(array_intersect_key($data, array_flip(['code', 'name', 'process', 'description', 'questions', 'status'])))->withAuditReason($reason)->save();

        return $template;
    }

    /** @return array<int, string> id => name of the active templates of a process (all processes when null) */
    public static function options(?string $process = null): array
    {
        return FeedbackTemplate::query()->active()->when($process, fn ($q) => $q->where('process', $process))->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, array<int, string>> bank name => [question id => title] of the active bank questions */
    public static function bankQuestionOptions(): array
    {
        return QuestionBankQuestion::query()->with('bank')->where('status', 'active')->whereHas('bank', fn ($q) => $q->where('status', 'active'))->orderBy('question_bank_id')->orderBy('sort_order')->get()
            ->groupBy(fn (QuestionBankQuestion $q) => $q->bank->name)->map(fn ($questions) => $questions->pluck('title', 'id')->all())->all();
    }
}
