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

        @if ($restriction = $this->restriction())
            {{-- Remediation I.9: say up front why changes are not possible here, and what can be done instead. --}}
            <x-pos.state variant="denied" title="You can view this policy, not change it." :why="$restriction" data-policy-restriction />
        @endif

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

        @php($run = $this->run())
        @if ($run)
            {{-- Remediation I.13: a large organisation's pre-flight runs on the queue; this follows it. --}}
            <section data-preflight-run="{{ $run->status }}" class="rounded-lg p-4 ring-1 ring-gray-950/5 dark:ring-white/10"
                @if ($run->isOpen()) wire:poll.5s="refreshRun" @endif>
                @php($states = ['pending' => ['Waiting to start', 'gray'], 'running' => ['Running', 'info'], 'completed' => ['Completed', 'success'], 'failed' => ['Failed', 'danger'], 'superseded' => ['Out of date', 'warning'], 'cancelled' => ['Cancelled', 'gray']])
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-semibold">Pre-flight in the background</h2>
                    <x-filament::badge :color="$states[$run->status][1] ?? 'gray'" class="min-w-max">{{ $states[$run->status][0] ?? $run->status }}</x-filament::badge>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    This organisation has more than {{ number_format(app(\App\Domain\Employment\Requirements\EmploymentRequirementsPreflight::class)->threshold()) }} people,
                    so the pre-flight reads them on the queue. Asked {{ $run->created_at?->diffForHumans() }}{{ $run->requester ? ' by '.$run->requester->name : '' }}.
                    @if ($run->isOpen() && $run->progress() !== null) {{ number_format($run->processed) }} of {{ number_format($run->total) }} people read ({{ $run->progress() }}%). @endif
                    @if ($run->status === 'completed' && ! $report) It no longer matches the policy, the companies it reaches or their own values, or it is older than {{ (int) config('peopleos.employment_requirements.preflight_evidence_hours', 24) }} hours: run it again before switching to Block. @endif
                </p>
                @if ($run->isOpen() && $run->progress() !== null)
                    <div class="mt-2 h-2 rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $run->progress() }}" aria-label="Pre-flight progress">
                        <div class="h-2 rounded-full bg-primary-600" style="width: {{ $run->progress() }}%"></div>
                    </div>
                @endif
                @if ($run->error)<p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $run->error }}</p>@endif
            </section>
        @endif

        @php($coverage = $this->coverage())
        @if ($coverage !== [])
            {{-- Remediation I.7: Block holds for a company only while a pre-flight covers its settings as they are now. --}}
            <section data-preflight-coverage>
                <h2 class="text-base font-semibold">Companies under Block</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400">A rule on Block blocks for a company only while a pre-flight covers its settings as they are now, its own values included. Otherwise it warns.</p>
                <div class="pos-step-table-wrap mt-2" tabindex="0" role="region" aria-label="Companies under Block">
                    <table class="pos-step-table">
                        <thead><tr><th>Company</th><th>Own values</th><th>Block</th></tr></thead>
                        <tbody>
                            @foreach ($coverage as $row)
                                <tr><td class="is-strong">{{ $row['company'] }}</td><td>{{ $row['own'] ? 'Yes' : 'No' }}</td><td>{{ $row['state'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @php($companyRuns = $this->companyRuns())
                @if ($companyRuns->isNotEmpty())
                    <details class="mt-2 text-sm"><summary>Recent checks of a company's own values ending</summary>
                        <ul class="mt-1 grid gap-1">
                            @foreach ($companyRuns as $item)
                                <li>{{ $item->company?->name }} · {{ $item->created_at?->format('j M Y H:i') }} · {{ ['covered' => 'ended and covered', 'refused' => 'not ended'][$item->outcome] ?? $item->status }}{{ $item->error ? ': '.$item->error : '' }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>
        @endif

        @if ($report)
            <section data-preflight>
                <h2 class="text-base font-semibold">Pre-flight on {{ \Illuminate\Support\Carbon::parse($report['on'])->format('j M Y') }}</h2>
                {{-- Companies outside the viewer's access are counted, never named (remediation I.9). One-line php directives
                     only in this view: a block form would swallow the one-line directives before it. --}}
                @php($mine = app(\App\Domain\Configuration\Applicability\EntityApplicability::class)->companiesFor(auth()->user()))
                @php($named = $mine === null ? $report['companies'] : array_intersect_key($report['companies'], array_flip($mine)))
                @php($others = count($report['companies']) - count($named))
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $report['employees'] }} current {{ $report['employees'] === 1 ? 'person' : 'people' }} this policy reaches,
                    in {{ count($report['companies']) }} {{ count($report['companies']) === 1 ? 'company' : 'companies' }}{{ $report['companies'] === [] ? '' : ': '.implode(', ', $named).($others > 0 ? ($named === [] ? '' : ' and ').$others.' outside your access' : '') }}.
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
