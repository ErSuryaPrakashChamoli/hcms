<?php

namespace App\Livewire\Experience;

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Services\AiGateway;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Experience\Services\UxMetrics;
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
        $lens = app(RoleLens::class)->primary($user);
        $preferred = ['employee' => 'employee', 'manager' => 'manager', 'hr' => 'hr', 'hr_admin' => 'hr', 'payroll' => 'payroll_auditor', 'executive' => 'workforce', 'system_admin' => 'policy'][$lens] ?? 'employee';
        $this->assistant = isset($available[$preferred]) ? $preferred : array_key_first($available);
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

        return array_slice(array_values(array_unique([...$this->context, ...app(AiGateway::class)->examples($this->assistant)])), 0, 5);
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
