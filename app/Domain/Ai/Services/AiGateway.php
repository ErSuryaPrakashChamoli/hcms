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
use RuntimeException;

/**
 * The permission-aware AI gateway (§93, §95): checks feature and permission, asks the assistant for a
 * grounded answer built from domain services under the user's own permissions, optionally lets the
 * language model rephrase those facts, logs everything, and never performs write actions.
 */
final class AiGateway
{
    /** @var array<string, class-string<Assistant>> */
    private const ASSISTANTS = [
        'employee' => EmployeeAssistant::class, 'policy' => PolicyAssistant::class, 'manager' => ManagerAssistant::class,
        'hr' => HrCopilot::class, 'payroll_auditor' => PayrollAuditorAssistant::class, 'workforce' => WorkforceAnalyst::class,
    ];

    public function __construct(private readonly AiProvider $provider, private readonly FeatureFlags $features, private readonly AuditRecorder $audit) {}

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

        $started = hrtime(true);
        $employee = Employee::query()->with('person')->where('user_id', $user->id)->first();
        $answer = $this->assistant($assistant)->answer($user, $employee, $question);

        $provider = 'deterministic';
        $model = null;
        $tokens = [0, 0];
        $text = $answer->answer;

        if ($this->features->enabled('ai.llm') && $answer->facts !== [] && $this->provider->name() !== 'none') {
            $completion = $this->provider->complete($this->systemPrompt($definition['label']), $this->userPrompt($question, $answer), 600);
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
            'question' => $question,
            'answer' => $text,
            'sources' => $answer->sources,
            'actions' => $answer->actions,
            'intent' => $answer->intent,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $tokens[0],
            'output_tokens' => $tokens[1],
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'is_inference' => $answer->isInference,
        ]);

        if ($answer->isInference) {
            $this->audit->record(AuditAction::View, 'ai', $interaction, [], null, actor: $user, metadata: ['assistant' => $assistant, 'intent' => $answer->intent, 'inference' => true]);
        }

        return $interaction;
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
        return "You are the {$label} inside an HR system. Answer the employee's question using ONLY the facts provided; if the facts do not cover the question, say so briefly. Be concise, friendly and specific. Never invent numbers, policies or people. Do not give legal or medical advice. Do not suggest changing any record; you cannot perform actions.";
    }

    private function userPrompt(string $question, AiAnswer $answer): string
    {
        $facts = $answer->facts;
        if (config('peopleos.ai.redact_identifiers', true)) {
            $facts = json_decode(preg_replace('/\b[A-Z]{2,5}\d{3,}\b/', '[id]', json_encode($facts)), true) ?? $facts;
        }

        return "Question: {$question}\n\nFacts (JSON):\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\nDeterministic draft answer:\n".$answer->answer;
    }
}
