<x-filament-panels::page>
    @php($readiness = $this->getReadiness())
    <div class="grid gap-4 md:grid-cols-4">
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Employees without a salary</div>
            <div class="text-2xl font-semibold {{ $readiness['no_salary'] > 0 ? 'text-warning-600' : '' }}">{{ $readiness['no_salary'] }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Without a primary bank account</div>
            <div class="text-2xl font-semibold {{ $readiness['no_bank'] > 0 ? 'text-warning-600' : '' }}">{{ $readiness['no_bank'] }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Without PAN</div>
            <div class="text-2xl font-semibold {{ $readiness['no_pan'] > 0 ? 'text-warning-600' : '' }}">{{ $readiness['no_pan'] }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Statutory rules in force</div>
            <div class="text-2xl font-semibold {{ $readiness['rules'] === 0 ? 'text-danger-600' : '' }}">{{ $readiness['rules'] }}</div>
        </x-filament::section>
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
