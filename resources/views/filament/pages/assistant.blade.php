<x-filament-panels::page>
    @php($assistants = $this->getAssistants())
    <div class="flex flex-wrap gap-2">
        @foreach ($assistants as $key => $label)
            <x-filament::button size="sm" :color="$key === $this->assistant ? 'primary' : 'gray'" wire:click="switchTo('{{ $key }}')">{{ $label }}</x-filament::button>
        @endforeach
    </div>
    <div class="text-sm text-gray-500">{{ $this->getDescription() }} · Answers come only from your own data and permissions; anything marked as an inference is a system-generated indicator, not a decision.</div>

    <x-filament::section>
        <div class="space-y-4">
            @forelse ($this->getHistory() as $turn)
                <div>
                    <div class="text-sm font-medium text-gray-600 dark:text-gray-300">You</div>
                    <div class="text-sm">{{ $turn->question }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
                    <div class="text-sm font-medium text-primary-600">{{ $assistants[$turn->assistant] ?? $turn->assistant }} @if ($turn->is_inference)<x-filament::badge color="warning" size="sm">inference</x-filament::badge>@endif @if ($turn->provider !== 'deterministic')<x-filament::badge color="gray" size="sm">{{ $turn->model }}</x-filament::badge>@endif</div>
                    <div class="text-sm whitespace-pre-line mt-1">{{ $turn->answer }}</div>
                    @if (! empty($turn->sources))
                        <div class="text-xs text-gray-500 mt-2">Sources: {{ collect($turn->sources)->map(fn ($s) => $s['label'] . (isset($s['detail']) ? ' (' . $s['detail'] . ')' : ''))->implode(' · ') }}</div>
                    @endif
                    <div class="flex flex-wrap items-center gap-2 mt-2">
                        @foreach ($turn->actions ?? [] as $action)
                            <x-filament::link :href="$action['url']" size="sm">{{ $action['label'] }} →</x-filament::link>
                        @endforeach
                        <span class="flex-1"></span>
                        @if ($turn->feedback)
                            <span class="text-xs text-gray-500">Rated {{ $turn->feedback === 'up' ? 'helpful' : 'not helpful' }}</span>
                        @else
                            <x-filament::icon-button icon="heroicon-m-hand-thumb-up" size="sm" color="gray" wire:click="rate({{ $turn->id }}, 'up')" label="Helpful" />
                            <x-filament::icon-button icon="heroicon-m-hand-thumb-down" size="sm" color="gray" wire:click="rate({{ $turn->id }}, 'down')" label="Not helpful" />
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-sm text-gray-500">Ask something, or try one of the examples below.</div>
            @endforelse
        </div>
    </x-filament::section>

    <x-filament::section compact>
        <form wire:submit="ask" class="flex gap-2">
            <x-filament::input.wrapper class="flex-1">
                <x-filament::input type="text" wire:model="question" placeholder="Ask the {{ $assistants[$this->assistant] ?? 'assistant' }}…" maxlength="1000" />
            </x-filament::input.wrapper>
            <x-filament::button type="submit">Ask</x-filament::button>
        </form>
        <div class="flex flex-wrap gap-2 mt-3">
            @foreach ($this->getExamples() as $example)
                <x-filament::button size="xs" color="gray" outlined wire:click="useExample('{{ addslashes($example) }}')">{{ $example }}</x-filament::button>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
