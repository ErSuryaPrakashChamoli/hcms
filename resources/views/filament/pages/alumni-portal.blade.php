<x-filament-panels::page>
    @php($p = $this->getProfile())
    <x-filament::section :heading="'Welcome back, ' . ($p->employee->person?->first_name ?? '')" :description="($p->last_designation ?? '') . ' · ' . ($p->joined_on?->toDateString() ?? '?') . ' – ' . $p->exited_on->toDateString()">
        <div class="text-sm text-gray-500">Employee code {{ $p->employee->employee_code }}. Request experience or relieving letters, salary certificates, employment verification, payslip copies or references from the button above. Every request is verified and audited.</div>
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-3">
        <x-filament::section heading="My requests" compact>
            @forelse ($this->getRequests() as $r)
                <div class="py-1 text-sm border-b border-gray-100 dark:border-gray-800 last:border-0">
                    <div class="flex justify-between"><span>{{ $r->number }} · {{ config('peopleos.alumni.request_types.' . $r->type) }}</span><x-filament::badge :color="$r->status === 'delivered' ? 'success' : ($r->status === 'rejected' ? 'danger' : 'warning')">{{ config('peopleos.alumni.request_statuses.' . $r->status) }}</x-filament::badge></div>
                    @if ($r->response)<div class="text-xs text-gray-500">{{ $r->response }}</div>@endif
                </div>
            @empty
                <div class="text-sm text-gray-500">No requests yet.</div>
            @endforelse
        </x-filament::section>
        <x-filament::section heading="My documents" compact>
            @forelse ($this->getDocuments() as $d)
                <div class="py-1 text-sm border-b border-gray-100 dark:border-gray-800 last:border-0 flex justify-between"><span>{{ $d['title'] }}<span class="text-gray-500"> · {{ $d['type'] }}</span></span><x-filament::link :href="$d['url']" size="sm" target="_blank">Download</x-filament::link></div>
            @empty
                <div class="text-sm text-gray-500">No documents on file.</div>
            @endforelse
        </x-filament::section>
        <x-filament::section heading="Payslips" compact>
            @forelse ($this->getPayslips() as $s)
                <div class="py-1 text-sm border-b border-gray-100 dark:border-gray-800 last:border-0 flex justify-between"><span>{{ $s['label'] }}</span><x-filament::link :href="$s['url']" size="sm">Net {{ number_format($s['net'], 2) }}</x-filament::link></div>
            @empty
                <div class="text-sm text-gray-500">No payslips available.</div>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
