<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Assistants\Assistant;
use App\Domain\Ai\Assistants\EmployeeAssistant;
use App\Domain\Ai\Assistants\HrCopilot;
use App\Domain\Ai\Assistants\ManagerAssistant;
use App\Domain\Ai\Assistants\PayrollAuditorAssistant;
use App\Domain\Ai\Assistants\PolicyAssistant;
use App\Domain\Ai\Assistants\WorkforceAnalyst;
use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Providers\AiProvider;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\FeatureFlags;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * The permission-aware AI gateway (§93, §95): checks feature and permission, asks the assistant for a
 * grounded answer built from domain services under the user's own permissions, optionally lets the
 * language model rephrase those facts, logs everything, and never performs write actions.
 *
 * Phase 14 assistive controls (ADR-0016):
 * - A per-user rate limit (peopleos.ai.rate_limit_per_minute).
 * - The AI data boundary (AiDataPolicy). Nothing leaves PeopleOS unless the tenant allows it.
 *   Prohibited data (passwords, keys, tokens, secrets) never leaves and is never stored. Restricted
 *   personal data leaves only under the "restricted" tenant policy. Each external call is audited
 *   with counts, never content.
 * - Language-model text is marked AI-generated.
 * - Suggested actions are proposals only: links to the existing screen, where the user reviews and
 *   confirms, and the existing domain action runs and audits. There is no execute path here, and an
 *   assistant can never mutate a domain.
 */
final class AiGateway
{
    /** @var array<string, class-string<Assistant>> */
    private const ASSISTANTS = [
        'employee' => EmployeeAssistant::class, 'policy' => PolicyAssistant::class, 'manager' => ManagerAssistant::class,
        'hr' => HrCopilot::class, 'payroll_auditor' => PayrollAuditorAssistant::class, 'workforce' => WorkforceAnalyst::class,
    ];

    public function __construct(private readonly AiProvider $provider, private readonly FeatureFlags $features, private readonly AuditRecorder $audit, private readonly AiDataPolicy $policy) {}

    /** @return array<string, string> key => label the user may use */
    public function assistantsFor(User $user): array
    {
        $out = [];
        foreach (config('peopleos.ai.assistants', []) as $key => $definition) {
            if ($this->features->enabled($definition['feature']) && ($user->hasPermission($definition['permission']) || $user->is_platform_admin)) {
                if ($key === 'manager' && ! $this->isManager($user)) {
                    continue;
                }
                $out[$key] = $definition['label'];
            }
        }

        return $out;
    }

    public function examples(string $assistant): array
    {
        return $this->assistant($assistant)->examples();
    }

    public function ask(User $user, string $assistant, string $question): AiInteraction
    {
        $definition = config("peopleos.ai.assistants.{$assistant}") ?? throw new RuntimeException('Unknown assistant.');
        if (! $this->features->enabled($definition['feature'])) {
            throw new RuntimeException($definition['label'].' is switched off for this tenant.');
        }
        if (! ($user->hasPermission($definition['permission']) || $user->is_platform_admin)) {
            throw new RuntimeException('You do not have access to '.$definition['label'].'.');
        }
        $question = trim($question);
        if ($question === '' || mb_strlen($question) > 1000) {
            throw new RuntimeException('Ask a question of up to 1000 characters.');
        }

        $limiterKey = 'ai-gateway:'.$user->tenant_id.':'.$user->id;
        $perMinute = max(1, (int) config('peopleos.ai.rate_limit_per_minute', 20));
        if (RateLimiter::tooManyAttempts($limiterKey, $perMinute)) {
            throw new RuntimeException('Too many questions in a short time. Try again in '.RateLimiter::availableIn($limiterKey).' seconds.');
        }
        RateLimiter::hit($limiterKey, 60);

        $started = hrtime(true);
        $employee = Employee::query()->with('person')->where('user_id', $user->id)->first();
        $answer = $this->assistant($assistant)->answer($user, $employee, $question);

        $provider = 'deterministic';
        $model = null;
        $tokens = [0, 0];
        $text = $answer->answer;
        $tenantPolicy = $this->policy->tenantPolicy();
        $boundary = ['policy' => $tenantPolicy, 'sent' => false, 'removed' => 0, 'redacted' => 0];

        if ($this->features->enabled('ai.llm') && $tenantPolicy !== 'none' && $answer->facts !== [] && $this->provider->name() !== 'none') {
            $facts = $this->policy->prepare($answer->facts, $tenantPolicy);
            $q = $this->policy->redactText($question, $tenantPolicy);
            $draft = $this->policy->redactText($answer->answer, $tenantPolicy);
            $boundary['removed'] = $facts['removed'] + $q['removed'] + $draft['removed'];
            $boundary['redacted'] = $facts['redacted'] + $q['redacted'] + $draft['redacted'];
            $boundary['sent'] = true;
            $completion = $this->provider->complete($this->systemPrompt($definition['label']), $this->userPrompt($q['text'], $draft['text'], $facts['facts']), 600);
            $this->audit->record(AuditAction::AiExternalRequest, 'ai', null, [], null, actor: $user, metadata: ['assistant' => $assistant, 'intent' => $answer->intent, 'provider' => $this->provider->name(),
                'policy' => $tenantPolicy, 'removed' => $boundary['removed'], 'redacted' => $boundary['redacted'], 'answered' => $completion !== null]);
            if ($completion !== null) {
                $text = $completion['text'];
                $provider = $this->provider->name();
                $model = $completion['model'];
                $tokens = [$completion['input_tokens'], $completion['output_tokens']];
            }
        }

        $interaction = AiInteraction::create([
            'user_id' => $user->id,
            'assistant' => $assistant,
            'question' => $this->policy->forLog($question),
            'answer' => $this->policy->forLog($text),
            'sources' => $answer->sources,
            'actions' => $this->proposals($answer->actions),
            'intent' => $answer->intent,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $tokens[0],
            'output_tokens' => $tokens[1],
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'is_inference' => $answer->isInference,
            'ai_generated' => $provider !== 'deterministic',
            'data_policy' => $boundary,
        ]);

        if ($answer->isInference) {
            $this->audit->record(AuditAction::View, 'ai', $interaction, [], null, actor: $user, metadata: ['assistant' => $assistant, 'intent' => $answer->intent, 'inference' => true]);
        }

        return $interaction;
    }

    /**
     * UX.15 contextual intelligence: record what PeopleOS showed a person in a context (an Employee 360, Home,
     * the Approval Center), so contextual answers stay auditable like questions. Deterministic statements
     * over data the viewer may already see; nothing is sent to an external model; prohibited values are
     * removed before logging. One record per viewer and context per hour.
     *
     * @param  list<array{text: string, source: string, action?: ?array}>  $insights
     */
    public function recordContext(User $user, string $context, array $insights): void
    {
        if ($insights === [] || $this->assistantsFor($user) === []) {
            return;
        }
        $key = 'ai-context:'.$user->tenant_id.':'.$user->id.':'.md5($context.'|'.implode('|', array_column($insights, 'text')));
        if (! Cache::add($key, true, now()->addHour())) {
            return;
        }
        AiInteraction::create([
            'user_id' => $user->id,
            'assistant' => 'intelligence',
            'question' => $this->policy->forLog('Context: '.$context),
            'answer' => $this->policy->forLog(implode("\n", array_map(fn (array $i) => '- '.$i['text'], $insights))),
            'sources' => array_values(array_unique(array_map(fn (array $i) => ['label' => $i['source']], $insights), SORT_REGULAR)),
            'actions' => array_values(array_filter(array_map(fn (array $i) => isset($i['action']['label']) ? ['label' => $i['action']['label'], 'url' => $i['action']['url'] ?? null] : null, $insights))),
            'intent' => 'context',
            'provider' => 'deterministic',
            'model' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'latency_ms' => 0,
            'is_inference' => false,
            'ai_generated' => false,
            'data_policy' => ['policy' => $this->policy->tenantPolicy(), 'sent' => false, 'removed' => 0, 'redacted' => 0],
        ]);
    }

    public function feedback(AiInteraction $interaction, string $feedback, ?string $note = null): AiInteraction
    {
        if (! in_array($feedback, ['up', 'down'], true)) {
            throw new RuntimeException('Feedback is up or down.');
        }
        $interaction->update(['feedback' => $feedback, 'feedback_note' => $note]);

        return $interaction;
    }

    private function assistant(string $key): Assistant
    {
        return app(self::ASSISTANTS[$key] ?? throw new RuntimeException('Unknown assistant.'));
    }

    private function isManager(User $user): bool
    {
        $employee = Employee::query()->where('user_id', $user->id)->first();

        return $employee !== null && $employee->directReports()->currentlyEffective()->exists();
    }

    private function systemPrompt(string $label): string
    {
        return "You are the {$label} inside an HR system. Answer the employee's question using ONLY the facts provided; if the facts do not cover the question, say so briefly. Be concise, friendly and specific. Never invent numbers, policies or people. Do not give legal or medical advice. Do not suggest changing any record; you cannot perform actions. Never ask for or repeat passwords, keys, tokens or identifiers.";
    }

    private function userPrompt(string $question, string $draft, array $facts): string
    {
        if (config('peopleos.ai.redact_identifiers', true)) {
            $facts = json_decode(preg_replace('/\b[A-Z]{2,5}\d{3,}\b/', '[id]', json_encode($facts)), true) ?? $facts;
            $draft = (string) preg_replace('/\b[A-Z]{2,5}\d{3,}\b/', '[id]', $draft);
        }

        return "Question: {$question}\n\nFacts (JSON):\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\nDeterministic draft answer:\n".$draft;
    }

    /**
     * Phase 14: suggested actions become proposals. Each is a link to an existing PeopleOS screen (never
     * an external URL, never an executable operation). The user reviews and confirms there, and the
     * existing domain action runs with its own authorization, workflow and audit.
     *
     * @param  array<int, array{label?: string, url?: string}>  $actions
     * @return list<array{label: string, url: string, kind: string, requires_confirmation: bool}>
     */
    public function proposals(array $actions): array
    {
        $hosts = array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), app()->runningInConsole() ? null : request()->getHost()]);

        return collect($actions)->filter(function ($a) use ($hosts) {
            $url = (string) ($a['url'] ?? '');
            if (($a['label'] ?? '') === '' || $url === '' || preg_match('/^\s*(javascript|data|vbscript):/i', $url)) {
                return false;
            }
            $parts = parse_url($url);

            return $parts !== false && (! isset($parts['host']) ? str_starts_with($url, '/') && ! str_starts_with($url, '//') : in_array($parts['host'], $hosts, true));
        })->map(fn ($a) => ['label' => (string) $a['label'], 'url' => (string) $a['url'], 'kind' => 'open_screen', 'requires_confirmation' => true])->values()->all();
    }
}
