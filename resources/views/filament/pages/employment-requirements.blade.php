<x-filament-panels::page>
    @php
        $policies = $this->policies();
        $policy = $this->policy();
        $settings = $this->settings();
        $report = $this->report();
        $fields = \App\Domain\Employment\Requirements\EmploymentRequirements::requirableFields();
        $modes = \App\Domain\Employment\Requirements\EmploymentRequirements::MODES;
        $tone = ['off' => 'gray', 'warn' => 'warning', 'block' => 'danger'];
    @endphp

    <p class="text-sm text-gray-600 dark:text-gray-400" style="max-width: 60rem">
        What a hire or a position change must name, checked for every hire, rehire, transfer and promotion on screens,
        in uploads and through the API. Warn lets the change through and shows what is missing; Block refuses it and is
        switched on only after the pre-flight below. Without a policy only the company is required.
    </p>

    @if ($policies->isEmpty())
        <x-pos.state variant="empty" title="No employment data requirements yet."
            why="Only the company is required on a hire or a change. Create a policy to require more, starting on Warn." />
    @else
        <nav class="pos-tabs" aria-label="Requirements policies">
            @foreach ($policies as $id => $item)
                <button type="button" class="pos-tab" wire:click="choose({{ $id }})" @if ($id === $this->policyId) aria-current="page" @endif>{{ $item->name }}</button>
            @endforeach
        </nav>

        @if ($policy && $settings)
            <section class="grid gap-3 md:grid-cols-2" data-requirements-settings>
                <div class="rounded-lg p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <h3 class="text-sm font-semibold">Required on a hire or rehire</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Company, {{ collect($settings['required_on_hire'])->map(fn ($c) => $fields[$c] ?? $c)->implode(', ') ?: 'nothing else' }}</p>
                    <h3 class="mt-3 text-sm font-semibold">Required on another change</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Company, {{ collect($settings['required_on_change'])->map(fn ($c) => $fields[$c] ?? $c)->implode(', ') ?: 'nothing else' }}</p>
                    <p class="mt-3 text-sm">When a required field is missing: <x-filament::badge :color="$tone[$settings['required_mode']]" class="inline-flex min-w-max">{{ $modes[$settings['required_mode']] }}</x-filament::badge></p>
                </div>
                <div class="rounded-lg p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <h3 class="text-sm font-semibold">Consistency checks</h3>
                    <ul class="mt-1 grid gap-1 text-sm">
                        @foreach (\App\Domain\Employment\Requirements\EmploymentRequirements::CHECKS as $check => [$label, $about])
                            <li class="flex items-center justify-between gap-3"><span title="{{ $about }}">{{ $label }}</span>
                                <x-filament::badge :color="$tone[$settings['checks'][$check]]" class="shrink-0 min-w-max">{{ $modes[$settings['checks'][$check]] }}</x-filament::badge></li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

        @if ($report)
            <section data-preflight>
                <h2 class="text-base font-semibold">Pre-flight on {{ \Illuminate\Support\Carbon::parse($report['on'])->format('j M Y') }}</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $report['employees'] }} current {{ $report['employees'] === 1 ? 'person' : 'people' }} this policy reaches,
                    in {{ count($report['companies']) }} {{ count($report['companies']) === 1 ? 'company' : 'companies' }}{{ $report['companies'] === [] ? '' : ': '.implode(', ', $report['companies']) }}.
                    A rule is safe to block when every company can meet it and nobody fails it today.
                </p>
                @if ($report['rules'] === [])
                    <x-pos.state variant="caught-up" title="No rule is switched on." why="Every rule of this policy is Off." />
                @else
                    <div class="pos-step-table-wrap mt-2" tabindex="0" role="region" aria-label="Pre-flight">
                        <table class="pos-step-table">
                            <thead><tr><th>Rule</th><th>Now</th><th>Would fail today</th><th>What stops Block</th><th>Safe to block</th></tr></thead>
                            <tbody>
                                @foreach ($report['rules'] as $rule => $row)
                                    <tr data-rule="{{ $rule }}">
                                        <td class="is-strong">{{ $row['label'] }}</td>
                                        <td><x-filament::badge :color="$tone[$row['mode']]" class="min-w-max">{{ $modes[$row['mode']] }}</x-filament::badge></td>
                                        <td>
                                            {{ $row['violations'] }}
                                            @if ($row['samples'] !== [])
                                                <details class="mt-1 text-xs text-gray-600 dark:text-gray-400"><summary>Examples</summary>
                                                    <ul class="list-disc pl-4">@foreach ($row['samples'] as $sample)<li>{{ $sample }}</li>@endforeach</ul></details>
                                            @endif
                                        </td>
                                        <td class="is-quiet">{{ $row['unconfigured'] === [] ? '—' : implode(' ', $row['unconfigured']) }}</td>
                                        <td>{{ $row['safe'] ? 'Yes' : ($row['unconfigured'] === [] ? 'With consent' : 'No') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    @endif
</x-filament-panels::page>
