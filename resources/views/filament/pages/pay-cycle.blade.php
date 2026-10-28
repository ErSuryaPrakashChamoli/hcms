<x-filament-panels::page>
    <div class="pos-panel pos-panel-pad pos-figures">
        @foreach ($this->summary() as $figure)
            <div class="pos-figure">
                <span class="pos-figure-value">{{ $figure['value'] }}</span>
                <span class="pos-figure-label">{{ $figure['label'] }}</span>
                <span class="pos-figure-delta">{{ $figure['delta'] }}</span>
            </div>
        @endforeach
    </div>

    <form wire:submit="save">
        {{ $this->form }}
        <div class="mt-4"><x-filament::button type="submit">Save pay cycle</x-filament::button></div>
    </form>

    @php($companies = $this->preview())
    <x-filament::section
        :heading="filled($this->data['starts_on'] ?? null) ? 'The months around the new cycle' : 'The coming months'"
        description="Before you save. Months that already have a payroll run keep their dates, and the month next to them starts or ends against them, so no day is paid twice or left out."
    >
        <div class="grid gap-6">
            @foreach ($companies as $row)
                <div>
                    @if (count($companies) > 1)
                        <p class="mb-2 text-sm font-medium">{{ $row['company'] }}</p>
                    @endif
                    <ol class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5" aria-label="{{ $row['company'] }}: payroll months">
                        @foreach ($row['months'] as $month)
                            <li @class([
                                'rounded-xl p-3 ring-1',
                                'bg-primary-50 ring-primary-500/40 dark:bg-primary-400/10 dark:ring-primary-400/40' => $month['state'] === 'first',
                                'bg-warning-50 ring-warning-500/40 dark:bg-warning-400/10 dark:ring-warning-400/40' => $month['state'] === 'changeover',
                                'bg-gray-50 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10' => in_array($month['state'], ['fixed', 'closed'], true),
                                'ring-gray-950/10 dark:ring-white/10' => $month['state'] === null,
                            ])>
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="text-sm font-semibold">{{ $month['label'] }}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $month['days'] }} days</span>
                                </div>
                                <p class="mt-1 text-sm tabular-nums">{{ $month['start']->format('j M') }} – {{ $month['end']->format('j M Y') }}</p>
                                @if ($month['note'])
                                    <p @class([
                                        'mt-2 text-xs',
                                        'text-primary-700 dark:text-primary-300' => $month['state'] === 'first',
                                        'text-warning-700 dark:text-warning-300' => $month['state'] === 'changeover',
                                        'text-gray-500 dark:text-gray-400' => in_array($month['state'], ['fixed', 'closed'], true),
                                    ])>{{ $month['note'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
