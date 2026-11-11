<x-filament-panels::page>
    {{-- The policy wizard in the step kit (theme.css .pos-step-*): the step rail on the left, the open step on the right,
         the review as one card per step with Edit, and Previous / Next naming the steps either side. --}}
    @php
        $steps = $this->steps();
        $current = $steps[$this->step] ?? $steps[0];
        $count = count($steps);
        $stepOf = fn (string $label) => collect($steps)->search(fn (array $s) => $s['label'] === $label);
    @endphp
    <div class="grid gap-6 lg:grid-cols-4">
        <nav aria-label="Policy steps">
            <x-filament::section compact>
                <ol class="pos-step-rail is-flush">
                    @foreach ($steps as $i => $s)
                        @php
                            $state = $i < $this->step ? 'done' : ($i === $this->step ? 'current' : 'waiting');
                            $open = $i <= $this->reached;
                        @endphp
                        <li data-state="{{ $state }}">
                            <button type="button" wire:click="goTo({{ $i }})" @disabled(! $open) class="pos-step-rail-btn" data-state="{{ $state }}"
                                @if ($i === $this->step) aria-current="step" @endif>
                                <span class="pos-step-rail-dot" aria-hidden="true">
                                    @if ($state === 'done')
                                        <x-filament::icon icon="heroicon-m-check" class="pos-step-rail-check" />
                                    @else
                                        {{ $i + 1 }}
                                    @endif
                                </span>
                                <span class="pos-step-rail-label">{{ $s['label'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        </nav>

        <div class="grid gap-6 lg:col-span-3">
            <form wire:submit="{{ $this->isReview() ? 'saveDraft' : 'next' }}">
                <x-filament::section :heading="($this->step + 1).'. '.$current['label']" :description="$current['help']">
                    <div class="pos-step">
                        <p class="pos-step-count" style="--pos-step-pct: {{ (int) round(($this->step + 1) * 100 / max(1, $count)) }}">Step {{ $this->step + 1 }} of {{ $count }}</p>

                        @if (in_array($current['key'], ['applicability', 'advance'], true) && $this->advancedRules)
                            <x-pos.step-banner tone="pending" icon="heroicon-o-adjustments-horizontal" title="Set by assignment rules"
                                sub="Who this policy applies to is set by assignment rules the wizard does not edit (more conditions or other comparisons).">
                                @if ($url = $this->rulesUrl())
                                    <x-slot:aside>
                                        <a href="{{ $url }}" wire:navigate class="pos-step-link">Open the rule editor<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-step-link-icon" /></a>
                                    </x-slot:aside>
                                @endif
                            </x-pos.step-banner>
                        @endif

                        @if ($this->isReview())
                            @php $review = $this->review(); @endphp
                            <x-pos.step-banner tone="idle" icon="heroicon-o-clipboard-document-check" title="Check the policy"
                                :sub="$this->canPublish() ? 'Save it as a draft, or publish it to start applying it.' : 'Save it as a draft; it can be published when it is ready.'" />
                            <div class="pos-step-review">
                                @foreach ([['Policy', $review['policy']], ...array_map(fn ($g) => [$g['label'], $g['rows']], $review['settings']), ['Applicability', $review['applicability']]] as [$heading, $rows])
                                    @php $target = $stepOf($heading); @endphp
                                    <section class="pos-step-group" aria-label="{{ $heading }}">
                                        <div class="pos-step-group-head">
                                            <h3 class="pos-step-subhead">{{ $heading }}</h3>
                                            @if ($target !== false && $target <= $this->reached)
                                                <button type="button" class="pos-step-link" wire:click="goTo({{ $target }})">Edit<span class="fi-sr-only"> {{ $heading }}</span><x-filament::icon icon="heroicon-m-pencil-square" class="pos-step-link-icon" /></button>
                                            @endif
                                        </div>
                                        @if ($rows === [])
                                            <p class="pos-step-note">Nothing set.</p>
                                        @else
                                            <dl class="pos-step-ledger">
                                                @foreach ($rows as [$label, $value])
                                                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                                                @endforeach
                                            </dl>
                                        @endif
                                    </section>
                                @endforeach
                            </div>
                        @endif

                        {{ $this->form }}

                        <div class="pos-step-foot">
                            <div>
                                @if ($this->step > 0)
                                    <x-filament::button color="gray" icon="heroicon-m-arrow-left" wire:click="previous" type="button">{{ $steps[$this->step - 1]['label'] }}</x-filament::button>
                                @endif
                            </div>
                            <div class="pos-step-foot-end">
                                <x-filament::link :href="\App\Filament\Resources\Policies\PolicyResource::getUrl('index')" color="gray">Cancel</x-filament::link>
                                @if ($this->isReview())
                                    <x-filament::button type="submit" color="gray" icon="heroicon-m-document">Save as draft</x-filament::button>
                                    @if ($this->canPublish())
                                        <x-filament::button type="button" wire:click="publish" color="success" icon="heroicon-m-paper-airplane">Publish</x-filament::button>
                                    @endif
                                @else
                                    <x-filament::button type="submit" icon="heroicon-m-arrow-right" icon-position="after">Next: {{ $steps[$this->step + 1]['label'] ?? 'Review' }}</x-filament::button>
                                @endif
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            </form>
        </div>
    </div>
</x-filament-panels::page>
