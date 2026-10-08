<?php

namespace App\Livewire\Experience;

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Services\AiGateway;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Experience\Services\UxMetrics;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * UX: contextual AI (§27–28). A thin presentation over AiGateway, which already enforces the feature
 * flag, the assistant permission, the rate limit, the data boundary for external models and the audit.
 * Answers are shown as Answer / Key facts / Sources / Suggested actions. It is assistive: suggested
 * actions only open screens, where people decide with the usual checks.
 */
class AiAssistant extends Component
{
    public bool $embedded = false;

    public ?string $assistant = null;

    public string $question = '';

    /** @var list<int> interaction ids asked in this panel */
    public array $thread = [];

    public ?string $error = null;

    /** @var list<string> contextual prompts supplied by the page that opened the panel */
    public array $context = [];

    public function mount(bool $embedded = false): void
    {
        $this->embedded = $embedded;
        $available = $this->assistants;
        $user = auth()->user();
        $this->assistant = self::preferredFor($user, $available);
    }

    /**
     * UX.16: the assistant and opening question for each experience (same lens as Home, including "Home opens as").
     * Only assistants the person may use are offered; the question is answered by that assistant's own
     * deterministic intents under the same permissions, scope, AI policy and audit as any other question.
     */
    public const ROLE_PROMPTS = [
        'employee' => ['employee', 'Explain my leave balance'],
        'manager' => ['manager', 'Summarise changes in my team'],
        'hr' => ['hr', 'What employee lifecycle actions need attention?'],
        'payroll' => ['payroll_auditor', 'Audit the latest payroll'],
        'executive' => ['workforce', 'What changed in my workforce?'],
        'admin' => ['hr', 'Which configurations changed recently?'],
    ];

    /** @param array<string, string> $available */
    public static function preferredFor(User $user, array $available): ?string
    {
        $experience = RoleLens::experienceOf(app(RoleLens::class)->primary($user, app(ExperiencePreferences::class)->for($user)['lens'] ?? null));
        $preferred = self::ROLE_PROMPTS[$experience][0] ?? 'employee';
        if (! isset($available[$preferred]) && $experience === 'admin') {
            $preferred = 'policy';
        }

        return isset($available[$preferred]) ? $preferred : array_key_first($available);
    }

    /** @return array<string, string> */
    #[Computed]
    public function assistants(): array
    {
        return auth()->check() ? app(AiGateway::class)->assistantsFor(auth()->user()) : [];
    }

    /** @return list<string> */
    #[Computed]
    public function prompts(): array
    {
        if ($this->assistant === null) {
            return [];
        }

        $user = auth()->user();
        $experience = $user ? RoleLens::experienceOf(app(RoleLens::class)->primary($user, app(ExperiencePreferences::class)->for($user)['lens'] ?? null)) : null;
        [$owner, $question] = self::ROLE_PROMPTS[$experience] ?? [null, null];
        $role = $owner === $this->assistant ? [$question] : [];

        return array_slice(array_values(array_unique([...$this->context, ...$role, ...app(AiGateway::class)->examples($this->assistant)])), 0, 5);
    }

    /** @return Collection<int, AiInteraction> */
    #[Computed]
    public function interactions()
    {
        return $this->thread === [] ? collect() : AiInteraction::query()->where('user_id', auth()->id())->whereKey($this->thread)->orderBy('id')->get();
    }

    #[On('pos-ai-open')]
    public function openWith(?string $prompt = null, ?string $assistant = null, array $context = []): void
    {
        if ($this->embedded) {
            return;
        }
        $this->context = array_values(array_filter(array_map('strval', $context)));
        if ($assistant !== null && isset($this->assistants[$assistant])) {
            $this->assistant = $assistant;
        }
        unset($this->prompts);
        if ($prompt !== null && trim($prompt) !== '') {
            $this->question = $prompt;
            $this->ask();
        }
    }

    public function switchTo(string $assistant): void
    {
        if (isset($this->assistants[$assistant])) {
            $this->assistant = $assistant;
            unset($this->prompts);
        }
    }

    public function usePrompt(string $prompt): void
    {
        $this->question = $prompt;
        $this->ask();
    }

    public function ask(): void
    {
        $this->error = null;
        if ($this->assistant === null) {
            $this->error = 'No assistant is available to you.';

            return;
        }
        try {
            $interaction = app(AiGateway::class)->ask(auth()->user(), $this->assistant, $this->question);
            $this->thread = array_slice([...$this->thread, $interaction->id], -6);
            $this->question = '';
            app(UxMetrics::class)->record('ai.asked');
            unset($this->interactions);
            if ($this->embedded) {
                $this->dispatch('pos-ai-answered');
            }
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function rate(int $id, string $feedback): void
    {
        if (! in_array($feedback, ['up', 'down'], true)) {
            return;
        }
        $interaction = AiInteraction::query()->where('user_id', auth()->id())->findOrFail($id);
        app(AiGateway::class)->feedback($interaction, $feedback);
        unset($this->interactions);
    }

    public function clear(): void
    {
        $this->thread = [];
        $this->error = null;
        unset($this->interactions);
    }

    public function render(): View
    {
        return view('livewire.experience.ai-assistant');
    }
}
