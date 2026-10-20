<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Events\SurveyResponseSubmitted;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\EngagementIdentity;
use App\Domain\Engagement\Models\SurveyAnswer;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyQuestion;
use App\Domain\Engagement\Models\SurveyResponse;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Support\Guard;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13: taking a survey. The anonymity boundary lives here (docs/architecture/
 * engagement-communication.md §3).
 *
 * One transaction:
 * 1. Shared-lock the version and require it open (a closing run waits, or wins first).
 * 2. Lock the employee's own participation and enforce the response rule.
 * 3. Mark the participation submitted (a date only).
 * 4. Write the response and answers, with no link back for anonymous and confidential surveys
 *    (confidential authorship goes only to engagement_identities).
 * 5. Audit anonymously.
 *
 * A retry after success changes nothing. No drafts are stored, and the result returned to the
 * caller carries no response id unless the survey is identified.
 */
final class SurveyResponses
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** The employee opened the survey (participation invited → opened, date only). */
    public function markOpened(SurveyVersion $version, User $user): void
    {
        $employee = $this->employeeOf($user);
        AccessScope::withoutScoping(fn () => SurveyParticipation::query()->where('survey_version_id', $version->id)->where('employee_id', $employee->id)
            ->where('status', 'invited')->update(['status' => 'opened', 'opened_on' => now()->toDateString()]));
    }

    /**
     * @param  array<string, mixed>  $answers  question key => value (array for multiple choice)
     * @return array{status: 'submitted'|'already_submitted', response_id: ?string}
     */
    public function submit(SurveyVersion $version, User $user, array $answers, ?string $idempotencyKey = null): array
    {
        Guard::authorise($user, 'engagement.participate');
        $employee = $this->employeeOf($user);
        $questions = SurveyQuestion::query()->where('survey_version_id', $version->id)->orderBy('position')->orderBy('id')->get();
        $values = $this->validate($questions, $answers);
        $idempotencyKey = $idempotencyKey !== null ? substr(trim($idempotencyKey), 0, 64) : null;
        if ($version->response_rule === 'multiple' && blank($idempotencyKey)) {
            throw new EngagementRuleViolation('A survey that accepts several responses needs an idempotency key per submission.');
        }

        try {
            $result = DB::transaction(function () use ($version, $employee, $user, $values, $idempotencyKey) {
                $current = SurveyVersion::query()->whereKey($version->id)->sharedLock()->firstOrFail();
                if ($current->status !== 'open' || ($current->closes_at !== null && $current->closes_at->isPast())) {
                    throw new EngagementRuleViolation('This survey is not open.');
                }
                $participation = SurveyParticipation::query()->withoutGlobalScope(AccessScope::class)
                    ->where('survey_version_id', $current->id)->where('employee_id', $employee->id)->lockForUpdate()->first();
                if ($participation === null || $participation->status === 'expired') {
                    throw new EngagementRuleViolation('You are not invited to this survey.');
                }
                $identified = $current->isIdentified();
                if ($identified && filled($idempotencyKey)) {
                    $existing = SurveyResponse::query()->where('survey_version_id', $current->id)->where('employee_id', $employee->id)->where('idempotency_key', $idempotencyKey)->value('id');
                    if ($existing !== null) {
                        return ['status' => 'already_submitted', 'response_id' => $existing];
                    }
                }
                $periodKey = $this->periodKey($current);
                if ($participation->status === 'submitted') {
                    if (! $identified || $current->response_rule === 'once') {
                        return ['status' => 'already_submitted', 'response_id' => null];
                    }
                    if ($current->response_rule === 'per_period' && SurveyResponse::query()->where('survey_version_id', $current->id)->where('employee_id', $employee->id)->where('period_key', $periodKey)->where('active_key', 1)->exists()) {
                        return ['status' => 'already_submitted', 'response_id' => null];
                    }
                }

                $participation->forceFill(['status' => 'submitted', 'submitted_on' => now()->toDateString()])->save();
                $response = SurveyResponse::query()->create([
                    'tenant_id' => $current->tenant_id, 'survey_version_id' => $current->id, 'group_key' => $participation->group_key, 'status' => 'submitted', 'active_key' => 1,
                    'employee_id' => $identified ? $employee->id : null,
                    'period_key' => $identified ? $periodKey : null,
                    'submitted_on' => $identified ? now()->toDateString() : null,
                    'idempotency_key' => $identified ? $idempotencyKey : null,
                ]);
                $this->writeAnswers($current, $response, $values);
                if ($current->anonymity_mode === 'confidential') {
                    EngagementIdentity::query()->create(['subject_type' => 'survey_response', 'subject_id' => $response->id, 'employee_id' => $employee->id]);
                }
                $this->audit->record(AuditAction::SurveyResponseSubmitted, 'engagement', $current, [], null,
                    actor: $identified ? $user : null, metadata: $identified ? ['mode' => 'identified', 'response_id' => $response->id] : ['mode' => $current->anonymity_mode], anonymous: ! $identified);

                return ['status' => 'submitted', 'response_id' => $identified ? $response->id : null, 'mode' => $current->anonymity_mode];
            });
        } catch (UniqueConstraintViolationException) {
            // The database refused a second response for the same person and period (or key).
            return ['status' => 'already_submitted', 'response_id' => null];
        }

        if ($result['status'] === 'submitted') {
            SurveyResponseSubmitted::dispatch((int) $version->tenant_id, (int) $version->id, $result['mode']);
        }

        return ['status' => $result['status'], 'response_id' => $result['response_id']];
    }

    /**
     * Identified surveys only, while open: the employee replaces their own answers. The old response
     * is kept (superseded) and the new one points to it. Anonymous and confidential responses are final:
     * correcting them would need a link back to the person.
     */
    public function correct(SurveyResponse $response, User $user, array $answers, string $reason): SurveyResponse
    {
        Guard::authorise($user, 'engagement.participate');
        $employee = $this->employeeOf($user);
        if (trim($reason) === '') {
            throw new EngagementRuleViolation('A correction needs a reason.');
        }
        $version = SurveyVersion::query()->findOrFail($response->survey_version_id);
        if (! $version->isIdentified()) {
            throw new EngagementRuleViolation('Anonymous and confidential responses are final and cannot be corrected.');
        }
        $values = $this->validate(SurveyQuestion::query()->where('survey_version_id', $version->id)->orderBy('position')->get(), $answers);

        return DB::transaction(function () use ($response, $version, $employee, $user, $values, $reason) {
            $current = SurveyVersion::query()->whereKey($version->id)->sharedLock()->firstOrFail();
            if ($current->status !== 'open') {
                throw new EngagementRuleViolation('Responses are corrected only while the survey is open.');
            }
            $old = SurveyResponse::query()->whereKey($response->id)->lockForUpdate()->firstOrFail();
            if ((int) $old->employee_id !== (int) $employee->id || $old->status !== 'submitted') {
                throw new EngagementRuleViolation('Only your own current response can be corrected.');
            }
            $old->update(['status' => 'superseded', 'active_key' => null]);
            $new = SurveyResponse::query()->create([
                'tenant_id' => $current->tenant_id, 'survey_version_id' => $current->id, 'employee_id' => $employee->id, 'group_key' => $old->group_key,
                'period_key' => $old->period_key, 'status' => 'submitted', 'active_key' => 1, 'supersedes_id' => $old->id, 'submitted_on' => now()->toDateString(),
            ]);
            $this->writeAnswers($current, $new, $values);
            $this->audit->record(AuditAction::SurveyResponseSubmitted, 'engagement', $current, [], $reason, actor: $user, metadata: ['mode' => 'identified', 'response_id' => $new->id, 'supersedes' => $old->id, 'correction' => true]);

            return $new;
        });
    }

    /**
     * The employee's own survey list: open versions they are invited to plus their history. For
     * identified surveys their own answers can be shown back. Anonymous and confidential ones show
     * "submitted" only, because there is nothing to link back to.
     *
     * @return Collection<int, array{version: SurveyVersion, participation: SurveyParticipation}>
     */
    public function mySurveys(User $user): Collection
    {
        $employee = Employee::query()->where('user_id', $user->id)->first();
        if ($employee === null) {
            return collect();
        }
        $participations = AccessScope::withoutScoping(fn () => SurveyParticipation::query()->where('employee_id', $employee->id)->orderByDesc('id')->limit(100)->get());
        $versions = SurveyVersion::query()->with('survey')->whereIn('id', $participations->pluck('survey_version_id'))->whereIn('status', ['open', 'closed', 'archived'])->get()->keyBy('id');

        return $participations->filter(fn ($p) => isset($versions[$p->survey_version_id]))
            ->map(fn ($p) => ['version' => $versions[$p->survey_version_id], 'participation' => $p])->values();
    }

    /** The identified responses of the signed-in employee to one version (never anonymous / confidential). */
    public function myIdentifiedResponses(SurveyVersion $version, User $user): Collection
    {
        if (! $version->isIdentified()) {
            return collect();
        }
        $employee = Employee::query()->where('user_id', $user->id)->first();

        return $employee === null ? collect() : SurveyResponse::query()->with('answers.question')->where('survey_version_id', $version->id)
            ->where('employee_id', $employee->id)->where('status', 'submitted')->get();
    }

    private function employeeOf(User $user): Employee
    {
        return AccessScope::withoutScoping(fn () => Employee::query()->where('user_id', $user->id)->first())
            ?? throw new EngagementRuleViolation('Surveys are taken by employees.');
    }

    private function periodKey(SurveyVersion $version): ?string
    {
        $today = Carbon::today();

        return match ($version->response_rule) {
            'once' => 'once',
            'per_period' => match ($version->response_period) {
                'week' => $today->format('o-\WW'),
                'quarter' => $today->year.'-Q'.$today->quarter,
                default => $today->format('Y-m'),
            },
            default => null,
        };
    }

    /**
     * @param  Collection<int, SurveyQuestion>  $questions
     * @return list<array{question: SurveyQuestion, option?: string, number?: float, date?: string, text?: string}>
     */
    private function validate(Collection $questions, array $answers): array
    {
        $byKey = $questions->keyBy('key');
        $unknown = array_diff(array_keys($answers), $byKey->keys()->all());
        if ($unknown !== []) {
            throw new EngagementRuleViolation('Unknown question: '.implode(', ', $unknown).'.');
        }
        $rows = [];
        foreach ($questions as $question) {
            $value = $answers[$question->key] ?? null;
            $empty = $value === null || $value === '' || $value === [];
            if ($empty) {
                if ($question->required) {
                    throw new EngagementRuleViolation("Please answer: {$question->prompt}");
                }

                continue;
            }
            $options = $question->choiceOptions();
            switch ($question->type) {
                case 'multiple_choice':
                    $selected = array_values(array_unique(array_map('strval', (array) $value)));
                    if (array_diff($selected, array_map('strval', array_keys($options))) !== []) {
                        throw new EngagementRuleViolation("That is not an option for: {$question->prompt}");
                    }
                    foreach ($selected as $option) {
                        $rows[] = ['question' => $question, 'option' => $option];
                    }
                    break;
                case 'single_choice':
                case 'yes_no':
                case 'likert':
                case 'rating':
                    if (is_array($value) || ! array_key_exists((string) $value, $options)) {
                        throw new EngagementRuleViolation("That is not an option for: {$question->prompt}");
                    }
                    $rows[] = ['question' => $question, 'option' => (string) $value] + (in_array($question->type, ['likert', 'rating'], true) ? ['number' => (float) $value] : []);
                    break;
                case 'number':
                    if (! is_numeric($value)) {
                        throw new EngagementRuleViolation("A number is needed for: {$question->prompt}");
                    }
                    $n = (float) $value;
                    if ((isset($question->scale['min']) && $n < (float) $question->scale['min']) || (isset($question->scale['max']) && $n > (float) $question->scale['max']) || abs($n) >= 1e8) {
                        throw new EngagementRuleViolation("The number is out of range for: {$question->prompt}");
                    }
                    $rows[] = ['question' => $question, 'number' => $n];
                    break;
                case 'date':
                    try {
                        $date = Carbon::createFromFormat('!Y-m-d', (string) $value);
                    } catch (\Throwable) {
                        $date = false;
                    }
                    if ($date === false || $date->format('Y-m-d') !== (string) $value) {
                        throw new EngagementRuleViolation("A date (YYYY-MM-DD) is needed for: {$question->prompt}");
                    }
                    $rows[] = ['question' => $question, 'date' => (string) $value];
                    break;
                case 'text':
                    $text = trim((string) (is_array($value) ? '' : $value));
                    if (mb_strlen($text) > 2000) {
                        throw new EngagementRuleViolation('Comments are limited to 2,000 characters.');
                    }
                    if ($text !== '') {
                        $rows[] = ['question' => $question, 'text' => $text];
                    } elseif ($question->required) {
                        throw new EngagementRuleViolation("Please answer: {$question->prompt}");
                    }
                    break;
            }
        }

        return $rows;
    }

    private function writeAnswers(SurveyVersion $version, SurveyResponse $response, array $rows): void
    {
        foreach ($rows as $row) {
            SurveyAnswer::query()->create([
                'tenant_id' => $version->tenant_id, 'survey_version_id' => $version->id, 'response_id' => $response->id, 'question_id' => $row['question']->id,
                'value_option' => $row['option'] ?? null, 'value_number' => $row['number'] ?? null, 'value_date' => $row['date'] ?? null, 'value_text' => $row['text'] ?? null,
            ]);
        }
    }
}
