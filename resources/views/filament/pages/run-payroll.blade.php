<x-filament-panels::page>
    {{-- Streamline S2: the guided payroll run. Step rail on the left, the open step on the right; each step is its own
         partial in run-payroll/ built from the step kit (x-pos.step-banner, x-pos.step-wait, .pos-step-* in theme.css). --}}
    @php($steps = $this->steps())
    @php($index = collect($steps)->search(fn ($s) => $s['key'] === $this->step))
    @php($index = $index === false ? 0 : $index)
    @php($current = $steps[$index])
    @php($run = $this->run())
    <div class="grid gap-6 lg:grid-cols-4" wire:init="load">
        <nav aria-label="Payroll steps">
            <x-filament::section compact>
                <div class="pos-step-pickers">
                    <label class="pos-step-picker">
                        <span>Company</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="company">
                                @foreach ($this->companies() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    <label class="pos-step-picker">
                        <span>Pay month</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="month">
                                @foreach ($this->monthOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                </div>
                <ol class="pos-step-rail">
                    @foreach ($steps as $i => $s)
                        <li data-state="{{ $s['state'] }}">
                            <button type="button" wire:click="goTo('{{ $s['key'] }}')" data-step="{{ $s['key'] }}" data-state="{{ $s['state'] }}" class="pos-step-rail-btn"
                                @if ($s['key'] === $current['key']) aria-current="step" @endif aria-label="{{ ($i + 1).'. '.$s['label'].': '.match ($s['state']) { 'done' => 'done', 'attention' => 'needs a look', 'current' => 'open', default => 'to do' } }}">
                                <span class="pos-step-rail-dot" aria-hidden="true">
                                    @if ($s['state'] === 'done')
                                        <x-filament::icon icon="heroicon-m-check" class="pos-step-rail-check" />
                                    @elseif ($s['state'] === 'attention')
                                        !
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
            <x-filament::section :heading="($index + 1).'. '.$current['label'].' · '.$this->monthLabel()" :description="$current['help']">
                <div class="pos-step">
                    @if ($this->companyId() === null)
                        <x-pos.step-wait icon="heroicon-o-building-office-2" title="Add a company first" text="Payroll runs per company." />
                    @elseif (! $this->ready)
                        <div class="pos-step-loading" role="status"><x-filament::loading-indicator class="pos-step-loading-icon" /> Loading the month…</div>
                    @else
                        @include('filament.pages.run-payroll.'.$current['key'])
                    @endif

                    <div class="pos-step-foot">
                        <div>
                            @if ($index > 0)
                                <x-filament::button color="gray" icon="heroicon-m-arrow-left" wire:click="previous" type="button">{{ $steps[$index - 1]['label'] }}</x-filament::button>
                            @endif
                        </div>
                        @if ($index < count($steps) - 1)
                            <x-filament::button :color="$current['state'] === 'done' ? 'primary' : 'gray'" icon="heroicon-m-arrow-right" icon-position="after" wire:click="next" type="button">
                                Next: {{ $steps[$index + 1]['label'] }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
