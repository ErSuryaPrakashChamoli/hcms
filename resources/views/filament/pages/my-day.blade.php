<x-filament-panels::page>
    @php($today = $this->getToday())
    @php($counters = $this->getCounters())
    @php($payslip = $this->getLatestPayslip())
    <div class="grid gap-4 md:grid-cols-4">
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Attendance today</div>
            <div class="text-xl font-semibold">{{ $today['status_label'] ?? ($today['checked_in'] ? 'Checked in' : 'Not checked in') }}</div>
            <div class="text-xs text-gray-500">
                @if ($today['first_in']) In {{ $today['first_in']->format('H:i') }} @endif
                @if ($today['last_out']) · Out {{ $today['last_out']->format('H:i') }} @endif
            </div>
            <div class="mt-3">
                @if ($today['checked_in'])
                    <x-filament::button size="sm" color="warning" wire:click="punch('out')">Check out</x-filament::button>
                @else
                    <x-filament::button size="sm" color="success" wire:click="punch('in')">Check in</x-filament::button>
                @endif
            </div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Leave balance</div>
            @forelse ($this->getLeaveBalances() as $b)
                <div class="flex justify-between text-sm"><span>{{ $b['name'] }}</span><span class="font-semibold">{{ number_format($b['available'], 1) }}</span></div>
            @empty
                <div class="text-sm text-gray-500">No leave policy applies yet.</div>
            @endforelse
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Latest payslip</div>
            @if ($payslip)
                <div class="text-xl font-semibold">{{ $payslip->get('period.label') }}</div>
                <div class="text-xs text-gray-500">Net {{ number_format((float) $payslip->get('totals.net'), 2) }}</div>
                <div class="mt-3"><x-filament::link :href="\App\Filament\Resources\Payslips\PayslipResource::getUrl('view', ['record' => $payslip])" size="sm">Open</x-filament::link></div>
            @else
                <div class="text-sm text-gray-500">No payslip yet.</div>
            @endif
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">This period</div>
            <div class="text-sm"><span class="font-semibold">{{ $counters['goals'] }}</span> active goals</div>
            <div class="text-sm"><span class="font-semibold">{{ $counters['learning'] }}</span> learning items open</div>
            <div class="text-sm"><span class="font-semibold">{{ $counters['announcements'] }}</span> announcements</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Needs attention" description="One decision. One next action.">
        @forelse ($this->getNeedsAttention() as $item)
            <div class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <div>
                    <x-filament::badge :color="$item['severity'] === 'danger' ? 'danger' : ($item['severity'] === 'warning' ? 'warning' : 'info')">{{ $item['count'] }}</x-filament::badge>
                    <span class="ml-2 font-medium">{{ $item['title'] }}</span>
                    <span class="ml-2 text-sm text-gray-500">{{ $item['detail'] }}</span>
                </div>
                @if ($item['url'])
                    <x-filament::link :href="$item['url']" size="sm">Go</x-filament::link>
                @endif
            </div>
        @empty
            <div class="text-sm text-gray-500">You're all caught up.</div>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
