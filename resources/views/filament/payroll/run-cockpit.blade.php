<x-filament-widgets::widget>
    {{-- HROne parity PO-4: the run cockpit (read-only). Steps and counters, step tables with CSV, reminders, activity. --}}
    @if ($run === null)
        <x-filament::section heading="Run cockpit">
            <p class="text-sm text-gray-500">Not available.</p>
        </x-filament::section>
    @else
        <x-filament::section heading="Run cockpit" description="Where this payroll month stands, step by step. The figures are read from the run, its inputs, the salary holds and the payslips; every action stays in the page header and the employees table.">
            <div class="grid gap-3 md:grid-cols-3" role="list" aria-label="Payroll steps">
                @foreach ($steps as $i => $step)
                    <div role="listitem" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700 {{ $step['state'] === 'current' ? 'ring-1 ring-primary-500' : '' }}" data-step="{{ $step['key'] }}" data-state="{{ $step['state'] }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold">{{ $i + 1 }}. {{ $step['label'] }}</span>
                            <x-filament::badge :color="match ($step['state']) { 'done' => 'success', 'current' => 'primary', default => 'gray' }">
                                {{ match ($step['state']) { 'done' => 'Done', 'current' => 'Now', default => 'Next' } }}
                            </x-filament::badge>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">{{ $step['optional'] ? 'Optional' : 'Mandatory' }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-y-1 text-sm">
                            @foreach ($step['counters'] as $counter)
                                <dt class="text-gray-500">{{ $counter['label'] }}</dt>
                                <dd class="tabular-nums text-end" aria-label="{{ $step['label'] }} · {{ $counter['label'] }}: {{ $counter['value'] ?? '—' }}">{{ $counter['value'] ?? '—' }}</dd>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section heading="Step tables" description="HROne's side tables of each step. Open one to see its rows (the first {{ $screenRows }}), or download it as CSV (audited).">
            @php
                $tableFacts = [
                'arrears' => 'Pending '.$counters['arrears_pending'].' · held '.$counters['arrears_held'].' · in this run '.$counters['arrears_in_run'].' · settled '.$counters['arrears_settled'].' · void '.$counters['arrears_void'],
                'overtime' => $counters['overtime_employees'].' employee(s) · '.rtrim(rtrim(number_format((float) $counters['overtime_hours'], 2, '.', ''), '0'), '.').' hour(s) · without an overtime component '.$counters['overtime_unpaid'],
                'inputs' => 'Variable pay '.$counters['variable_in_run'].' (approved, not in this run '.$counters['variable_waiting'].') · claims '.$counters['claims_in_run'].' ('.$counters['claims_waiting'].') · loan instalments '.$counters['loan_recoveries_in_run'].' · loan payouts '.$counters['loan_payouts_in_run'].' ('.$counters['loan_payouts_waiting'].') · encashments '.$counters['encashments_in_run'].' ('.$counters['encashments_waiting'].') · other inputs '.$counters['other_inputs_in_run'].' · adjustments approved '.$counters['adjustments_approved'].', waiting '.$counters['adjustments_waiting'],
                'holds' => 'Held '.$counters['holds_held'].' · released '.$counters['holds_released'],
                'entries' => 'Calculated '.$counters['calculated'].' · checked '.$counters['reviewed'].' · exceptions '.$counters['exceptions'].' · warnings '.$counters['warnings'],
                ];
            @endphp
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Step tables">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1 pe-3">Table</th><th class="py-1 pe-3">Counters</th><th class="py-1 pe-3"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($tables as $key => $label)
                            <tr class="border-t border-gray-200 dark:border-gray-700" data-table="{{ $key }}">
                                <td class="py-1 pe-3 font-medium">{{ $label }}</td>
                                <td class="py-1 pe-3 text-gray-600 dark:text-gray-400">{{ $tableFacts[$key] }}</td>
                                <td class="py-1 pe-3 whitespace-nowrap text-end">
                                    <x-filament::button size="xs" color="gray" wire:click="toggleTable('{{ $key }}')" :aria-pressed="$openTable === $key ? 'true' : 'false'">{{ $openTable === $key ? 'Hide' : 'Open' }}</x-filament::button>
                                    <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="download('{{ $key }}')">CSV</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($sources !== [])
                <p class="mt-2 text-xs text-gray-500">Inputs this run carries: {{ collect($sources)->map(fn ($s) => $s['label'].' '.$s['records'].' ('.$s['employees'].' employee(s))')->implode(' · ') }}</p>
            @endif

            @if ($open !== null)
                <div class="mt-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <p class="font-semibold">{{ $tables[$openTable] }} · {{ count($open['rows']) }}</p>
                    @if ($open['rows'] === [])
                        <p class="mt-2 text-sm text-gray-500">Nothing in this table for the run.</p>
                    @else
                        <div class="mt-2 overflow-x-auto" tabindex="0" role="region" aria-label="{{ $tables[$openTable] }}">
                            <table class="w-full text-sm">
                                <thead><tr class="text-left text-gray-500">@foreach ($open['columns'] as $heading)<th class="py-1 pe-3">{{ $heading }}</th>@endforeach</tr></thead>
                                <tbody>
                                    @foreach (array_slice($open['rows'], 0, $screenRows) as $row)
                                        <tr class="border-t border-gray-200 dark:border-gray-700">
                                            @foreach (array_keys($open['columns']) as $column)<td class="py-1 pe-3">{{ is_float($row[$column] ?? null) ? number_format($row[$column], 2) : ($row[$column] ?? '') }}</td>@endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if (count($open['rows']) > $screenRows)<p class="mt-2 text-xs text-gray-500">Showing {{ $screenRows }} of {{ count($open['rows']) }}; the CSV has every row.</p>@endif
                    @endif
                </div>
            @endif
        </x-filament::section>

        <div class="grid gap-4 md:grid-cols-2">
            <x-filament::section heading="Reminders" description="What still waits on payroll, across companies, in your access. Each opens its register.">
                @if ($reminders === [])
                    <p class="text-sm text-gray-500">No reminder registers open for you.</p>
                @else
                    <ul class="space-y-1 text-sm" aria-label="Payroll reminders">
                        @foreach ($reminders as $reminder)
                            <li class="flex items-center justify-between gap-2" data-reminder="{{ $reminder['key'] }}">
                                <x-filament::link :href="$reminder['url']" size="sm">{{ $reminder['label'] }}</x-filament::link>
                                <span class="tabular-nums font-semibold {{ $reminder['count'] > 0 ? 'text-warning-600' : 'text-gray-500' }}" aria-label="{{ $reminder['label'] }}: {{ $reminder['count'] }}">{{ $reminder['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>

            <x-filament::section heading="Run activity" description="From the run's audit trail: calculations, approvals, finalization, payment, downloads and salary checks.">
                @if ($activity === [])
                    <p class="text-sm text-gray-500">Nothing yet.</p>
                @else
                    <ul class="space-y-2 text-sm" aria-label="Run activity">
                        @foreach ($activity as $item)
                            <li>
                                <span class="font-medium">{{ $item['what'] }}</span>
                                <span class="block text-xs text-gray-500">{{ $item['when']->format('j M Y, H:i') }} · {{ $item['by'] }}{{ $item['detail'] ? ' · '.$item['detail'] : '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </div>
    @endif
</x-filament-widgets::widget>
