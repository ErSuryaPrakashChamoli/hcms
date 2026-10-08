<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Engagement\Events\EngagementEvent;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Jobs\SendSurveyInvitations;
use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Models\Survey;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyQuestion;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Support\Guard;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 13: the survey catalogue and version lifecycle.
 *
 * Draft → In review → Approved → Scheduled → Open → Closed → Archived.
 *
 * - A version is prepared by one person and approved by another (separation of duties), or decided
 *   by a configured workflow through EngagementWorkflowBridge.
 * - Content freezes on submission. A correction is a new version, and responses stay pinned to the
 *   version they answered.
 * - Opening takes the audience snapshot (participations with group keys) as one bulk operation and
 *   queues the invitations. Only one version of a survey is open at a time.
 *
 * Every transition locks the version row and checks lock_version.
 */
final class Surveys
{
    public function __construct(
        private readonly AudienceQuery $audiences,
        private readonly GroupKeys $groups,
        private readonly AuditRecorder $audit,
        private readonly WorkflowEngine $workflows,
    ) {}

    /** @param  array<string, mixed>  $data  survey identity plus the first version's settings */
    public function create(array $data, User $actor): Survey
    {
        Guard::authorise($actor, 'engagement.manage');
        if (blank($data['code'] ?? null) || blank($data['name'] ?? null)) {
            throw new EngagementRuleViolation('A survey needs a code and a name.');
        }
        $type = $data['survey_type'] ?? 'engagement';
        if (! array_key_exists($type, config('peopleos.engagement.survey_types'))) {
            throw new EngagementRuleViolation('Unknown survey type.');
        }

        try {
            return DB::transaction(function () use ($data, $type, $actor) {
                $survey = Survey::query()->create([
                    'code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'] ?? null, 'survey_type' => $type,
                    'category' => array_key_exists($data['category'] ?? '', config('peopleos.engagement.categories')) ? $data['category'] : 'engagement', 'owner_id' => $actor->id,
                ]);
                $this->audit->record(AuditAction::SurveyCreated, 'engagement', $survey, [], null, actor: $actor, metadata: ['type' => $type]);
                $this->createVersion($survey, $this->settings($data + ['anonymity_mode' => 'anonymous']), $actor);

                return $survey->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new EngagementRuleViolation('A survey with that code already exists.');
        }
    }

    /** A new draft version copying the latest version's settings and questions (the correction path). */
    public function newVersion(Survey $survey, User $actor): SurveyVersion
    {
        Guard::authorise($actor, 'engagement.manage');

        return DB::transaction(function () use ($survey, $actor) {
            Survey::query()->whereKey($survey->id)->lockForUpdate()->firstOrFail();
            if (SurveyVersion::query()->where('survey_id', $survey->id)->whereIn('status', ['draft', 'in_review'])->exists()) {
                throw new EngagementRuleViolation('This survey already has a version being prepared.');
            }
            $latest = SurveyVersion::query()->where('survey_id', $survey->id)->orderByDesc('version')->firstOrFail();
            $version = $this->createVersion($survey, array_intersect_key($latest->getAttributes(), array_flip(['intro', 'anonymity_mode', 'response_rule', 'response_period', 'audience_id', 'breakdown_dimension'])) + [
                'audience_criteria' => $latest->audience_criteria, 'result_visibility' => $latest->result_visibility, 'reminder_policy' => $latest->reminder_policy,
            ], $actor);
            foreach ($latest->questions as $question) {
                SurveyQuestion::query()->create([...array_intersect_key($question->getAttributes(), array_flip(['key', 'position', 'type', 'prompt', 'help', 'required'])),
                    'options' => $question->options, 'scale' => $question->scale, 'admin_metadata' => $question->admin_metadata, 'scoring' => $question->scoring, 'analysis_tags' => $question->analysis_tags,
                    'survey_version_id' => $version->id]);
            }

            return $version;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateDraft(SurveyVersion $version, array $data, User $actor): SurveyVersion
    {
        Guard::authorise($actor, 'engagement.manage');
        if ($version->status !== 'draft') {
            throw new EngagementRuleViolation('Only a draft version is edited; create a new version to change a submitted one.');
        }
        $version->update($this->settings($data + $version->only(['anonymity_mode', 'response_rule', 'response_period', 'breakdown_dimension', 'result_visibility', 'reminder_policy', 'audience_id', 'audience_criteria', 'intro', 'opens_at', 'closes_at'])));

        return $version;
    }

    /** @param  array<string, mixed>  $data */
    public function saveQuestion(SurveyVersion $version, array $data, User $actor, ?SurveyQuestion $question = null): SurveyQuestion
    {
        Guard::authorise($actor, 'engagement.manage');
        if ($version->status !== 'draft' || ($question && (int) $question->survey_version_id !== (int) $version->id)) {
            throw new EngagementRuleViolation('Questions are edited on a draft version only.');
        }
        $clean = $this->question($data);
        try {
            if ($question) {
                $question->update($clean);

                return $question;
            }

            return SurveyQuestion::query()->create([...$clean, 'survey_version_id' => $version->id,
                'position' => $data['position'] ?? ((int) SurveyQuestion::query()->where('survey_version_id', $version->id)->max('position') + 1)]);
        } catch (UniqueConstraintViolationException) {
            throw new EngagementRuleViolation('Another question of this version already uses that key.');
        }
    }

    public function removeQuestion(SurveyQuestion $question, User $actor): void
    {
        Guard::authorise($actor, 'engagement.manage');
        $question->delete();
    }

    public function submit(SurveyVersion $version, User $actor): SurveyVersion
    {
        Guard::authorise($actor, 'engagement.manage');

        return $this->move($version, ['draft'], 'in_review', $actor, function (SurveyVersion $current) use ($actor) {
            $this->assertReady($current);
            $criteria = $current->audience_id ? (Audience::query()->whereKey($current->audience_id)->where('status', 'active')->value('criteria') ?? throw new EngagementRuleViolation('The chosen audience is inactive.')) : ($current->audience_criteria ?? []);
            $criteria = $this->audiences->normalise(is_array($criteria) ? $criteria : json_decode((string) $criteria, true));
            if (! $this->audiences->withinScope($criteria, $actor)) {
                throw new EngagementRuleViolation('The audience names people or organisation units outside your scope.');
            }
            if ($this->audiences->count($criteria, $actor, $current->opens_at ?? now()) === 0) {
                throw new EngagementRuleViolation('The audience reaches nobody in your scope.');
            }

            return ['audience_criteria' => $criteria, 'prepared_by' => $actor->id, 'scope_user_id' => $actor->id, 'submitted_at' => now(), 'decision_note' => null];
        }, AuditAction::Submitted, 'survey.review_requested', function (SurveyVersion $submitted) use ($actor) {
            $key = config('peopleos.engagement.approval_workflows.survey');
            $workflow = $key ? Workflow::query()->where('key', $key)->where('status', 'active')->first() : null;
            if ($workflow && $workflow->published()->exists()) {
                $instance = $this->workflows->start($workflow, $submitted, ['survey' => ['code' => $submitted->survey->code, 'version' => $submitted->version]], $actor);
                SurveyVersion::query()->whereKey($submitted->id)->update(['workflow_instance_id' => $instance->id]);
                $submitted->workflow_instance_id = $instance->id;
            }
        });
    }

    public function approve(SurveyVersion $version, ?string $note, User $actor): SurveyVersion
    {
        Guard::authorise($actor, 'engagement.approve');

        return $this->move($version, ['in_review'], 'approved', $actor, function (SurveyVersion $current) use ($actor, $note) {
            if ($current->workflow_instance_id) {
                throw new EngagementRuleViolation('This version is decided by its approval workflow.');
            }
            Guard::notPreparer($current->prepared_by, $actor, 'approve');
            if (! $this->audiences->withinScope($current->audience_criteria ?? [], $actor)) {
                throw new EngagementRuleViolation('The audience is outside your scope; an approver who covers it must decide.');
            }

            return ['approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note, 'checksum' => $this->checksum($current)];
        }, AuditAction::SurveyApproved, 'survey.approved');
    }

    /** Send back for changes (returns to draft) or reject; either way a note is required. */
    public function returnToDraft(SurveyVersion $version, string $note, User $actor): SurveyVersion
    {
        Guard::authorise($actor, 'engagement.approve');
        if (trim($note) === '') {
            throw new EngagementRuleViolation('Returning a survey version needs a note.');
        }

        return $this->move($version, ['in_review', 'approved'], 'draft', $actor, function (SurveyVersion $current) use ($actor, $note) {
            if ($current->workflow_instance_id) {
                throw new EngagementRuleViolation('This version is decided by its approval workflow.');
            }
            Guard::notPreparer($current->prepared_by, $actor, 'return');

            return ['decision_note' => $note, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null, 'checksum' => null];
        }, AuditAction::Rejected, 'survey.returned');
    }

    /**
     * Approved → scheduled for its open date (opens straight away when that date has come). A null
     * actor is an approved campaign launching it.
     */
    public function publish(SurveyVersion $version, ?User $actor): SurveyVersion
    {
        if ($actor !== null) {
            Guard::authorise($actor, 'engagement.manage', 'engagement.approve');
        }
        $version = $this->move($version, ['approved'], 'scheduled', $actor, function (SurveyVersion $current) {
            if ($current->checksum !== $this->checksum($current)) {
                throw new EngagementRuleViolation('The approved content no longer matches its checksum.');
            }
            $opens = $current->opens_at ?? now();
            if ($current->closes_at !== null && $current->closes_at->lte($opens)) {
                throw new EngagementRuleViolation('The closing date has already passed; create a new version.');
            }
            // "Opens on publication" becomes a fixed date now; the fingerprint is re-stamped with it.
            $fixed = (clone $current)->forceFill(['opens_at' => $opens]);

            return ['published_at' => now(), 'opens_at' => $opens, 'checksum' => $this->checksum($fixed)];
        }, AuditAction::SurveyPublished, 'survey.published');

        return $version->opens_at->lte(now()) ? $this->open($version, $actor) : $version;
    }

    /** Campaign launch: publish an approved version; false when it is not (or no longer) approved. */
    public function publishApproved(SurveyVersion $version, ?User $actor = null): bool
    {
        try {
            $this->publish($version, $actor);

            return true;
        } catch (EngagementRuleViolation) {
            return false;
        }
    }

    /**
     * Scheduled → open: snapshot the eligible population (effective-dated, inside the preparer's
     * scope) with group keys, as one audited bulk operation, then queue invitations.
     */
    public function open(SurveyVersion $version, ?User $actor = null): SurveyVersion
    {
        $opened = null;
        $operationId = $this->audit->operation('engagement', 'Survey audience snapshot', function (string $operationId) use ($version, $actor, &$opened) {
            return DB::transaction(function () use ($version, $actor, $operationId, &$opened) {
                Survey::query()->whereKey($version->survey_id)->lockForUpdate()->firstOrFail();
                $current = SurveyVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'scheduled') {
                    if ($current->status === 'open') {
                        $opened = $current;

                        return 0;
                    }
                    throw new EngagementRuleViolation("A survey version that is {$current->status} cannot open.");
                }
                if (SurveyVersion::query()->where('survey_id', $current->survey_id)->where('status', 'open')->whereKeyNot($current->id)->lockForUpdate()->exists()) {
                    throw new EngagementRuleViolation('Another version of this survey is still open; close it first.');
                }
                $day = now()->toDateString();
                $scopeUser = $current->scope_user_id ? User::query()->find($current->scope_user_id) : null;
                $ids = AccessScope::withoutScoping(fn () => $this->audiences->query($current->audience_criteria ?? [], $scopeUser, $day)->orderBy('employees.id')->pluck('employees.id')->map(fn ($id) => (int) $id)->all());
                if ($ids === []) {
                    throw new EngagementRuleViolation('The audience reaches nobody today; the survey stays scheduled.');
                }
                $keys = $this->groups->assign($current->breakdown_dimension, $ids, $day);
                foreach (array_chunk($ids, 500) as $chunk) {
                    SurveyParticipation::query()->insert(array_map(fn (int $id) => [
                        'tenant_id' => $current->tenant_id, 'survey_version_id' => $current->id, 'employee_id' => $id, 'group_key' => $keys[$id] ?? null,
                        'status' => 'invited', 'invited_on' => $day, 'reminders_sent' => 0,
                    ], $chunk));
                }
                $version->setRawAttributes($current->getAttributes(), true);
                $version->update(['status' => 'open', 'opened_at' => now(), 'eligible_count' => count($ids), 'operation_id' => $operationId, 'lock_version' => $current->lock_version + 1]);
                $this->audit->record(AuditAction::AudienceUsed, 'engagement', $version, [], null, actor: $actor, metadata: ['purpose' => 'survey', 'eligible' => count($ids), 'criteria' => array_keys($current->audience_criteria ?? [])]);
                $this->audit->record(AuditAction::SurveyOpened, 'engagement', $version, [['field' => 'status', 'before' => 'scheduled', 'after' => 'open']], null, actor: $actor, metadata: ['eligible' => count($ids)]);
                $opened = $version;

                return ['succeeded' => count($ids)];
            });
        }, entityType: SurveyParticipation::class);

        if ($opened->operation_id === $operationId) {
            DB::afterCommit(fn () => SendSurveyInvitations::dispatch((int) $opened->tenant_id, (int) $opened->id));
            EngagementEvent::dispatch('survey.opened', $opened, $this->refs($opened), array_filter([(int) $opened->prepared_by]));
        }

        return $opened;
    }

    /** Open → closed: unsubmitted participations expire; results of anonymous / confidential surveys become available. */
    public function close(SurveyVersion $version, ?User $actor = null, ?string $reason = null): SurveyVersion
    {
        if ($actor !== null) {
            Guard::authorise($actor, 'engagement.manage', 'engagement.approve');
        }

        return DB::transaction(function () use ($version, $actor, $reason) {
            $current = SurveyVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'open') {
                if ($current->status === 'closed') {
                    return $version->setRawAttributes($current->getAttributes(), true);
                }
                throw new EngagementRuleViolation("A survey version that is {$current->status} cannot close.");
            }
            $expired = SurveyParticipation::query()->withoutGlobalScope(AccessScope::class)->where('survey_version_id', $current->id)
                ->whereIn('status', ['invited', 'opened'])->update(['status' => 'expired', 'expired_on' => now()->toDateString()]);
            $version->setRawAttributes($current->getAttributes(), true);
            $version->update(['status' => 'closed', 'closed_at' => now(), 'lock_version' => $current->lock_version + 1]);
            $this->audit->record(AuditAction::SurveyClosed, 'engagement', $version, [['field' => 'status', 'before' => 'open', 'after' => 'closed']], $reason, actor: $actor, metadata: ['eligible' => $current->eligible_count, 'expired' => $expired]);
            EngagementEvent::dispatch('survey.closed', $version, $this->refs($version), array_filter([(int) $version->prepared_by]));

            return $version;
        });
    }

    /** Closed → archived; a version that never opened can be withdrawn (archived) with a reason. */
    public function archive(SurveyVersion $version, ?string $reason, User $actor): SurveyVersion
    {
        Guard::authorise($actor, 'engagement.manage', 'engagement.approve');

        return $this->move($version, ['draft', 'in_review', 'approved', 'scheduled', 'closed'], 'archived', $actor, function (SurveyVersion $current) use ($reason) {
            if ($current->status !== 'closed' && trim((string) $reason) === '') {
                throw new EngagementRuleViolation('Withdrawing a survey version that never ran needs a reason.');
            }

            return ['archived_at' => now(), 'workflow_instance_id' => null];
        }, AuditAction::SurveyArchived, 'survey.archived', reason: $reason);
    }

    /** Checksum of the frozen content (settings and questions, including hidden metadata). */
    public function checksum(SurveyVersion $version): string
    {
        $questions = SurveyQuestion::query()->where('survey_version_id', $version->id)->orderBy('position')->orderBy('id')->get()
            ->map(fn (SurveyQuestion $q) => [$q->key, $q->position, $q->type, $q->prompt, $q->help, $q->required, $q->options, $q->scale, $q->admin_metadata, $q->scoring, $q->analysis_tags])->all();

        return hash('sha256', json_encode([
            $version->survey_id, $version->version, $version->intro, $version->anonymity_mode, $version->response_rule, $version->response_period, $version->audience_criteria,
            $version->breakdown_dimension, $version->result_visibility, $version->reminder_policy, $version->opens_at?->toIso8601String(), $version->closes_at?->toIso8601String(), $questions,
        ]));
    }

    /** @return array<string, mixed> references for events (never answers or people) */
    public function refs(SurveyVersion $version): array
    {
        $survey = $version->survey()->first();

        return ['survey' => $survey?->name, 'code' => $survey?->code, 'version' => $version->version, 'closes_at' => $version->closes_at?->toDateString()];
    }

    private function createVersion(Survey $survey, array $settings, User $actor): SurveyVersion
    {
        $next = (int) SurveyVersion::query()->where('survey_id', $survey->id)->max('version') + 1;
        $version = SurveyVersion::query()->create([...$settings, 'survey_id' => $survey->id, 'version' => $next, 'status' => 'draft', 'prepared_by' => $actor->id]);
        $this->audit->record(AuditAction::SurveyVersionCreated, 'engagement', $version, [], null, actor: $actor, metadata: ['version' => $next]);

        return $version;
    }

    /** @return array<string, mixed> validated version settings */
    private function settings(array $data): array
    {
        $mode = $data['anonymity_mode'] ?? 'anonymous';
        if (! array_key_exists($mode, config('peopleos.engagement.anonymity_modes'))) {
            throw new EngagementRuleViolation('Unknown anonymity mode.');
        }
        $rule = $data['response_rule'] ?? 'once';
        if (! array_key_exists($rule, config('peopleos.engagement.response_rules'))) {
            throw new EngagementRuleViolation('Unknown response rule.');
        }
        if ($mode !== 'identified' && $rule !== 'once') {
            throw new EngagementRuleViolation('Anonymous and confidential surveys allow exactly one response per person.');
        }
        $period = $rule === 'per_period' ? ($data['response_period'] ?? null) : null;
        if ($rule === 'per_period' && ! array_key_exists((string) $period, config('peopleos.engagement.response_periods'))) {
            throw new EngagementRuleViolation('A per-period survey needs a period (week, month or quarter).');
        }
        $dimension = filled($data['breakdown_dimension'] ?? null) ? $data['breakdown_dimension'] : null;
        if ($dimension !== null && ! array_key_exists($dimension, config('peopleos.engagement.breakdown_dimensions'))) {
            throw new EngagementRuleViolation('Unknown breakdown dimension.');
        }
        $visibility = array_map('boolval', array_intersect_key(($data['result_visibility'] ?? []) + ['hr' => true, 'managers' => false, 'employees' => false], array_flip(['hr', 'managers', 'employees'])));
        if ($visibility['managers'] && $dimension !== 'manager') {
            throw new EngagementRuleViolation('Managers can see team results only when the breakdown is by line manager.');
        }
        $defaults = config('peopleos.engagement.reminders');
        $policy = $data['reminder_policy'] ?? [];
        $reminders = [
            'after_days' => array_values(array_slice(array_unique(array_map('intval', array_filter((array) ($policy['after_days'] ?? $defaults['after_days']), fn ($d) => (int) $d > 0))), 0, 3)),
            'closing_days_before' => isset($policy['closing_days_before']) && $policy['closing_days_before'] !== '' ? max(0, (int) $policy['closing_days_before']) : $defaults['closing_days_before'],
            'max' => min(3, max(0, (int) ($policy['max'] ?? $defaults['max']))),
        ];
        $opens = filled($data['opens_at'] ?? null) ? Carbon::parse($data['opens_at']) : null;
        $closes = filled($data['closes_at'] ?? null) ? Carbon::parse($data['closes_at']) : null;
        if ($opens && $closes && $closes->lte($opens)) {
            throw new EngagementRuleViolation('A survey closes after it opens.');
        }
        $criteria = $data['audience_criteria'] ?? null;

        return [
            'intro' => $data['intro'] ?? null, 'anonymity_mode' => $mode, 'response_rule' => $rule, 'response_period' => $period, 'breakdown_dimension' => $dimension,
            'result_visibility' => $visibility, 'reminder_policy' => $reminders, 'opens_at' => $opens, 'closes_at' => $closes,
            'audience_id' => filled($data['audience_id'] ?? null) ? (int) $data['audience_id'] : null,
            'audience_criteria' => is_array($criteria) ? $this->audiences->normalise($criteria) : null,
        ];
    }

    /** @return array<string, mixed> validated question attributes */
    private function question(array $data): array
    {
        $key = Str::snake(trim((string) ($data['key'] ?? '')));
        if (! preg_match('/^[a-z0-9_]{1,64}$/', $key)) {
            throw new EngagementRuleViolation('A question needs a short key (letters, digits, underscores).');
        }
        $type = (string) ($data['type'] ?? '');
        if (! array_key_exists($type, config('peopleos.engagement.question_types'))) {
            throw new EngagementRuleViolation('Unknown question type.');
        }
        if (trim((string) ($data['prompt'] ?? '')) === '') {
            throw new EngagementRuleViolation('A question needs a prompt.');
        }
        $options = null;
        if (in_array($type, ['single_choice', 'multiple_choice'], true)) {
            $options = [];
            foreach ((array) ($data['options'] ?? []) as $k => $option) {
                $label = trim((string) (is_array($option) ? ($option['label'] ?? $option['value'] ?? '') : $option));
                $value = Str::limit(Str::snake((string) (is_array($option) ? ($option['value'] ?? $label) : (is_int($k) ? $label : $k))), 64, '');
                if ($label !== '' && $value !== '') {
                    $options[$value] = ['value' => $value, 'label' => $label];
                }
            }
            $options = array_values($options);
            if (count($options) < 2 || count($options) > 50) {
                throw new EngagementRuleViolation('A choice question needs between 2 and 50 options.');
            }
        }
        $scale = null;
        if (in_array($type, ['rating', 'number'], true)) {
            $min = $data['scale']['min'] ?? ($type === 'rating' ? 1 : null);
            $max = $data['scale']['max'] ?? ($type === 'rating' ? 5 : null);
            if ($type === 'rating' && ((int) $min < 0 || (int) $max > 10 || (int) $min >= (int) $max)) {
                throw new EngagementRuleViolation('A rating scale runs between 0 and 10 with min below max.');
            }
            $scale = array_filter(['min' => $min === null || $min === '' ? null : (float) $min, 'max' => $max === null || $max === '' ? null : (float) $max, 'labels' => $data['scale']['labels'] ?? null], fn ($v) => $v !== null);
        }

        return [
            'key' => $key, 'type' => $type, 'prompt' => trim((string) $data['prompt']), 'help' => $data['help'] ?? null, 'required' => (bool) ($data['required'] ?? false),
            'options' => $options, 'scale' => $scale, 'admin_metadata' => $data['admin_metadata'] ?? null, 'scoring' => $data['scoring'] ?? null, 'analysis_tags' => $data['analysis_tags'] ?? null,
        ];
    }

    private function assertReady(SurveyVersion $version): void
    {
        if (! SurveyQuestion::query()->where('survey_version_id', $version->id)->exists()) {
            throw new EngagementRuleViolation('A survey version needs at least one question.');
        }
        if ($version->closes_at === null) {
            throw new EngagementRuleViolation('A survey version needs a closing date.');
        }
        if ($version->closes_at->isPast()) {
            throw new EngagementRuleViolation('The closing date has already passed.');
        }
        if ($version->audience_id === null && empty($version->audience_criteria)) {
            throw new EngagementRuleViolation('Choose an audience (a saved audience or criteria); a survey is never sent to "everyone" by default.');
        }
    }

    private function move(SurveyVersion $version, array $from, string $to, ?User $actor, callable $changes, AuditAction $action, string $event, ?callable $after = null, ?string $reason = null): SurveyVersion
    {
        return DB::transaction(function () use ($version, $from, $to, $actor, $changes, $action, $event, $after, $reason) {
            $current = SurveyVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->lock_version !== (int) $version->lock_version) {
                throw new EngagementRuleViolation('The survey version was changed meanwhile. Reload and try again.');
            }
            if (! in_array($current->status, $from, true)) {
                throw new EngagementRuleViolation("A survey version that is {$current->status} cannot become ".str_replace('_', ' ', $to).'.');
            }
            $before = $current->status;
            $extra = $changes($current);
            $version->setRawAttributes($current->getAttributes(), true);
            $version->update(['status' => $to, 'lock_version' => $current->lock_version + 1, ...$extra]);
            $this->audit->record($action, 'engagement', $version, [['field' => 'status', 'before' => $before, 'after' => $to]], $reason ?? ($extra['decision_note'] ?? null), actor: $actor, metadata: ['event' => $event]);
            $recipients = match ($event) {
                'survey.review_requested' => Guard::holders('engagement.approve', [(int) $actor?->id]),
                default => array_values(array_filter([(int) $version->prepared_by], fn ($id) => $id !== (int) $actor?->id)),
            };
            EngagementEvent::dispatch($event, $version, $this->refs($version), $recipients);
            if ($after) {
                $after($version);
            }

            return $version;
        });
    }
}
