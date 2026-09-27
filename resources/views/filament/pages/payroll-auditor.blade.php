<x-filament-panels::page>
    @php($run = $this->getRun())
    @if (! $run)
        <x-filament::section>No calculated payroll run to audit yet.</x-filament::section>
    @else
        @php($findings = $this->getFindings())
        @php($counts = collect($findings)->countBy('severity'))
        <x-filament::section :heading="$run->period->label() . ' · ' . $run->company->name" :description="$run->total('employees') . ' employees · net ' . number_format($run->total('net'), 2) . ' · ' . config('peopleos.payroll.run_statuses.' . $run->status)">
            <div class="flex gap-3 text-sm">
                <x-filament::badge color="danger">{{ $counts['high'] ?? 0 }} high</x-filament::badge>
                <x-filament::badge color="warning">{{ $counts['medium'] ?? 0 }} medium</x-filament::badge>
                <x-filament::badge color="gray">{{ $counts['low'] ?? 0 }} low</x-filament::badge>
                <span class="text-gray-500">System-generated indicators for a reviewer. Thresholds: net change {{ config('peopleos.ai.payroll_audit.net_change_pct') }}%, deductions {{ config('peopleos.ai.payroll_audit.deduction_share_pct') }}% of gross, LOP {{ config('peopleos.ai.payroll_audit.lop_days') }} days, TDS jump {{ config('peopleos.ai.payroll_audit.tds_jump_pct') }}%, salary revision {{ config('peopleos.ai.payroll_audit.salary_revision_pct') }}%.</span>
            </div>
        </x-filament::section>
        <x-filament::section heading="Findings">
            @forelse ($findings as $f)
                <div class="flex items-start gap-3 py-2 border-b border-gray-100 dark:border-gray-800 last:border-0 text-sm">
                    <x-filament::badge :color="$f['severity'] === 'high' ? 'danger' : ($f['severity'] === 'medium' ? 'warning' : 'gray')">{{ $f['severity'] }}</x-filament::badge>
                    <div class="flex-1">
                        <div><span class="font-medium">{{ $f['employee'] }}</span> — {{ $f['message'] }}</div>
                        @if (! empty($f['evidence']))<div class="text-xs text-gray-500">{{ collect($f['evidence'])->map(fn ($v, $k) => $k . ': ' . (is_scalar($v) ? $v : json_encode($v)))->implode(' · ') }}</div>@endif
                    </div>
                    <x-filament::badge color="gray" size="sm">{{ str_replace('_', ' ', $f['type']) }}</x-filament::badge>
                </div>
            @empty
                <div class="text-sm text-gray-500">Nothing unusual against the configured thresholds.</div>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>
