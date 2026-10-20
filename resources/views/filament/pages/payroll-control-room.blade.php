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
            <x-filament::section :heading="$row['company']->name" :description="'Payroll for ' . $row['period_label']">
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

    {{ $this->table }}
</x-filament-panels::page>
