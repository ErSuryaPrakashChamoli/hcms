<x-filament-panels::page>
    @php($readiness = $this->getReadiness())
    <div class="pos-panel pos-panel-pad pos-figures">
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $readiness['no_salary'] > 0 ? 'warning' : '' }}">{{ $readiness['no_salary'] }}</span><span class="pos-figure-label">Employees without a salary</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $readiness['no_bank'] > 0 ? 'warning' : '' }}">{{ $readiness['no_bank'] }}</span><span class="pos-figure-label">Without a primary bank account</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $readiness['no_pan'] > 0 ? 'warning' : '' }}">{{ $readiness['no_pan'] }}</span><span class="pos-figure-label">Without PAN</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $readiness['rules'] === 0 ? 'bad' : '' }}">{{ $readiness['rules'] }}</span><span class="pos-figure-label">Statutory rules in force</span></div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->getCompanies() as $row)
            <x-filament::section :heading="$row['company']->name" :description="'Payroll for ' . $row['period_label'] . ' · ' . $row['period_dates']">
                <dl class="grid grid-cols-2 gap-y-1 text-sm">
                    <dt class="text-gray-500">Status</dt>
                    <dd>
                        <x-filament::badge :color="$row['status'] === 'not_started' ? 'gray' : \App\Filament\Resources\PayrollRuns\PayrollRunResource::statusColor($row['status'])">
                            {{ $row['status'] === 'not_started' ? 'Not started' : config('peopleos.payroll.run_statuses.' . $row['status'], $row['status']) }}
                        </x-filament::badge>
                    </dd>
                    <dt class="text-gray-500">Headcount</dt>
                    <dd>{{ $row['headcount'] }}</dd>
                    <dt class="text-gray-500">Without salary</dt>
                    <dd class="{{ $row['without_salary'] > 0 ? 'text-warning-600' : '' }}">{{ $row['without_salary'] }}</dd>
                    <dt class="text-gray-500">Statutory profile</dt>
                    <dd>{{ $row['profile'] ? 'Configured' : 'Defaults (not configured)' }}</dd>
                    @if ($row['run'])
                        <dt class="text-gray-500">Net pay</dt>
                        <dd>{{ number_format($row['run']->total('net'), 2) }}</dd>
                        <dt class="text-gray-500">Exceptions</dt>
                        <dd>{{ $row['run']->exception_count }}</dd>
                    @endif
                </dl>
                @if ($row['url'])
                    <div class="mt-3">
                        <x-filament::link :href="$row['url']" size="sm">Open run</x-filament::link>
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    {{-- HROne parity PO-3: pre-run readiness (read-only; informs, never blocks a run). Loads after the page. --}}
    <x-filament::section heading="Before you run payroll" description="Read-only checks for each company's current pay month. They inform and never stop a run: calculation, validation and finalize keep their own rules. Statutory thresholds come only from verified rules.">
        <div wire:init="loadReadiness">
            <div class="grid gap-3 text-sm md:grid-cols-3">
                <label class="flex flex-col gap-1">Company
                    <select wire:model.live="readinessCompany" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                        <option value="">Every company</option>
                        @foreach ($this->readinessCompanies() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </label>
                <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="readinessActiveOnly" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800"> Active employees only</label>
            </div>

            @php($preRun = $this->readiness())
            @if ($preRun === null)
                <p class="mt-3 text-sm text-gray-500" wire:loading.remove>Checking…</p>
            @else
                @if ($preRun['months'] !== [])
                    <p class="mt-3 text-xs text-gray-500">Pay month: {{ collect($preRun['months'])->map(fn ($m) => $m['company'].' · '.$m['label'].' ('.$m['dates'].')')->implode('; ') }}</p>
                @endif
                @foreach ($preRun['notes'] as $note)<p class="mt-2 text-xs text-gray-500">{{ $note }}</p>@endforeach

                <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($preRun['checks'] as $key => $check)
                        <button type="button" wire:click="openReadinessCheck('{{ $key }}')" class="rounded-lg border border-gray-200 p-3 text-start dark:border-gray-700 {{ $readinessCheck === $key ? 'ring-1' : '' }}" aria-pressed="{{ $readinessCheck === $key ? 'true' : 'false' }}" data-check="{{ $key }}">
                            <span class="flex items-center justify-between gap-2">
                                <span class="font-medium">{{ $check['label'] }}</span>
                                <span class="tabular-nums font-semibold {{ $check['state'] === 'attention' ? 'text-warning-600' : 'text-gray-500' }}" aria-label="{{ $check['label'] }}: {{ $check['count'] ?? ($check['state'] === 'no_rule' ? 'no verified rule' : 'not run') }}">{{ $check['count'] ?? ($check['state'] === 'no_rule' ? 'No verified rule' : 'Not run') }}</span>
                            </span>
                            <span class="mt-2 block text-xs text-gray-500">{{ $check['description'] }}</span>
                            @foreach ($check['notes'] as $note)<span class="mt-2 block text-xs text-gray-500">{{ $note }}</span>@endforeach
                        </button>
                    @endforeach
                </div>

                @if ($readinessCheck && isset($preRun['checks'][$readinessCheck]))
                    @php($open = $preRun['checks'][$readinessCheck])
                    <div class="mt-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-semibold">{{ $open['label'] }} · {{ $open['count'] ?? 0 }}</p>
                            @if (($open['count'] ?? 0) > 0)
                                <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadReadiness('{{ $readinessCheck }}')">Download CSV</x-filament::button>
                            @endif
                        </div>
                        @if (($open['count'] ?? 0) === 0)
                            <p class="mt-2 text-sm text-gray-500">{{ $open['state'] === 'no_rule' ? 'This check does not run without a verified rule.' : ($open['state'] === 'not_run' ? 'Nothing to compare with yet.' : 'Nothing to fix.') }}</p>
                        @else
                            <div class="mt-2 overflow-x-auto" tabindex="0" role="region" aria-label="{{ $open['label'] }}">
                                <table class="w-full text-sm">
                                    <thead><tr class="text-left text-gray-500">@foreach ($open['columns'] as $heading)<th class="py-1 pe-3">{{ $heading }}</th>@endforeach</tr></thead>
                                    <tbody>
                                        @foreach (array_slice($open['rows'], 0, 200) as $row)
                                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                                @foreach (array_keys($open['columns']) as $column)<td class="py-1 pe-3">{{ is_float($row[$column] ?? null) ? number_format($row[$column], 2) : ($row[$column] ?? '') }}</td>@endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if (count($open['rows']) > 200)<p class="mt-2 text-xs text-gray-500">Showing 200 of {{ count($open['rows']) }}; the CSV has every row.</p>@endif
                        @endif
                    </div>
                @endif
            @endif
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
