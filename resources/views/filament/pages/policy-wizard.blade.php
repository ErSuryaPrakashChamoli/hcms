<x-filament-panels::page>
    @php($steps = $this->steps())
    @php($current = $steps[$this->step] ?? $steps[0])
    <div class="grid gap-6 lg:grid-cols-4">
        <nav aria-label="Policy steps">
            <x-filament::section compact>
                <ol class="grid gap-1">
                    @foreach ($steps as $i => $s)
                        @php($done = $i < $this->step)
                        @php($open = $i <= $this->reached)
                        <li>
                            <button type="button" wire:click="goTo({{ $i }})" @disabled(! $open)
                                @class([
                                    'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start text-sm',
                                    'bg-primary-50 font-semibold text-primary-700 dark:bg-primary-400/10 dark:text-primary-300' => $i === $this->step,
                                    'hover:underline' => $i !== $this->step && $open,
                                    'text-gray-400' => ! $open,
                                ])
                                @if ($i === $this->step) aria-current="step" @endif>
                                <span aria-hidden="true"
                                    style="display:inline-flex;align-items:center;justify-content:center;flex:none;width:1.6rem;height:1.6rem;border-radius:9999px;font-size:.75rem;font-weight:600;{{ $i === $this->step ? 'background:var(--primary-600);color:#fff;' : ($done ? 'background:var(--primary-100);color:var(--primary-700);' : 'box-shadow:inset 0 0 0 1px var(--gray-300);') }}">
                                    @if ($done) &#10003; @else {{ $i + 1 }} @endif
                                </span>
                                <span>{{ $s['label'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        </nav>

        <div class="grid gap-6 lg:col-span-3">
            <form wire:submit="{{ $this->isReview() ? 'saveDraft' : 'next' }}">
                <x-filament::section :heading="($this->step + 1).'. '.$current['label']" :description="$current['help']">
                    @if (in_array($current['key'], ['applicability', 'advance'], true) && $this->advancedRules)
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Who this policy applies to is set by assignment rules the wizard does not edit (more conditions or other comparisons).
                            @if ($url = $this->rulesUrl())
                                Change them in the <a href="{{ $url }}" wire:navigate class="text-primary-600 hover:underline dark:text-primary-400">rule editor</a>.
                            @endif
                        </p>
                    @endif

                    @if ($this->isReview())
                        @php($review = $this->review())
                        <div class="grid gap-6">
                            @foreach ([['Policy', $review['policy']], ...array_map(fn ($g) => [$g['label'], $g['rows']], $review['settings']), ['Applicability', $review['applicability']]] as [$heading, $rows])
                                <section>
                                    <h3 class="text-sm font-semibold">{{ $heading }}</h3>
                                    <dl class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                                        @forelse ($rows as [$label, $value])
                                            <div class="flex justify-between gap-3 border-b border-gray-100 py-1 dark:border-white/10">
                                                <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                                <dd class="text-end">{{ $value }}</dd>
                                            </div>
                                        @empty
                                            <p class="text-gray-500 dark:text-gray-400">Nothing set.</p>
                                        @endforelse
                                    </dl>
                                </section>
                            @endforeach
                        </div>
                        <div class="mt-6"></div>
                    @endif

                    {{ $this->form }}

                    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            @if ($this->step > 0)
                                <x-filament::button color="gray" wire:click="previous" type="button">Previous</x-filament::button>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <x-filament::link :href="\App\Filament\Resources\Policies\PolicyResource::getUrl('index')" color="gray">Cancel</x-filament::link>
                            @if ($this->isReview())
                                <x-filament::button type="submit" color="gray">Save as draft</x-filament::button>
                                @if ($this->canPublish())
                                    <x-filament::button type="button" wire:click="publish" color="success">Publish</x-filament::button>
                                @endif
                            @else
                                <x-filament::button type="submit">Next</x-filament::button>
                            @endif
                        </div>
                    </div>
                </x-filament::section>
            </form>
        </div>
    </div>
</x-filament-panels::page>
