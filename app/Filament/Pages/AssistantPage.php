<?php

namespace App\Filament\Pages;

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Services\AiGateway;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\RoleLens;
use App\Livewire\Experience\AiAssistant;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/** The assistant chat (§94): pick an assistant you are entitled to, ask, see sources and next actions, rate the answer. */
class AssistantPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'Assistant';

    protected static ?string $title = 'Assistant';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.assistant';

    #[Url]
    public ?string $assistant = null;

    public string $question = '';

    public static function canAccess(): bool
    {
        return auth()->user() !== null && app(AiGateway::class)->assistantsFor(auth()->user()) !== [];
    }

    public function mount(): void
    {
        $available = $this->getAssistants();
        if ($this->assistant === null || ! isset($available[$this->assistant])) {
            // UX.16: open on the assistant for the person's experience, as the shell panel does.
            $this->assistant = AiAssistant::preferredFor(auth()->user(), $available);
        }
    }

    /** @return array<string, string> */
    public function getAssistants(): array
    {
        return app(AiGateway::class)->assistantsFor(auth()->user());
    }

    public function getExamples(): array
    {
        if ($this->assistant === null) {
            return [];
        }
        // UX.16: the role's own question first when this is that role's assistant.
        $experience = RoleLens::experienceOf(app(RoleLens::class)->primary(auth()->user(), app(ExperiencePreferences::class)->for(auth()->user())['lens'] ?? null));
        [$owner, $question] = AiAssistant::ROLE_PROMPTS[$experience] ?? [null, null];

        return array_values(array_unique([...($owner === $this->assistant ? [$question] : []), ...app(AiGateway::class)->examples($this->assistant)]));
    }

    public function getDescription(): string
    {
        return config("peopleos.ai.assistants.{$this->assistant}.description", '');
    }

    public function getHistory(): Collection
    {
        return AiInteraction::query()->where('user_id', auth()->id())->where('assistant', $this->assistant)->latest('id')->limit((int) config('peopleos.ai.history_limit', 20))->get()->reverse()->values();
    }

    public function switchTo(string $assistant): void
    {
        if (isset($this->getAssistants()[$assistant])) {
            $this->assistant = $assistant;
        }
    }

    public function useExample(string $example): void
    {
        $this->question = $example;
        $this->ask();
    }

    public function ask(): void
    {
        try {
            app(AiGateway::class)->ask(auth()->user(), $this->assistant, $this->question);
            $this->question = '';
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Cannot answer')->body($e->getMessage())->send();
        }
    }

    public function rate(int $interactionId, string $feedback): void
    {
        $interaction = AiInteraction::query()->where('user_id', auth()->id())->findOrFail($interactionId);
        app(AiGateway::class)->feedback($interaction, $feedback);
        Notification::make()->success()->title('Thanks for the feedback')->send();
    }
}
