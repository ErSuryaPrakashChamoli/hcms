<x-filament-panels::page>
    <x-filament::section heading="Team learning status" description="Employees you manage through configured relationship types.">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Employee</th><th>Open</th><th>Overdue</th><th>Mandatory overdue</th><th>Completed</th><th>Certificates expiring (60 d)</th></tr></thead>
            <tbody>
                @forelse ($this->members() as $m)
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="py-1">{{ $m['name'] }} <span class="text-gray-500">{{ $m['code'] }}</span></td>
                        <td>{{ $m['open'] }}</td>
                        <td>@if ($m['overdue']) <x-filament::badge color="danger">{{ $m['overdue'] }}</x-filament::badge> @else 0 @endif</td>
                        <td>@if ($m['mandatory_overdue']) <x-filament::badge color="danger">{{ $m['mandatory_overdue'] }}</x-filament::badge> @else 0 @endif</td>
                        <td>{{ $m['completed'] }}</td>
                        <td>@if ($m['expiring']) <x-filament::badge color="warning">{{ $m['expiring'] }}</x-filament::badge> @else 0 @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500">No team members.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-2">
        <x-filament::section heading="Requests awaiting your decision">
            <ul class="text-sm">
                @forelse ($this->pendingApprovals() as $e)
                    <li class="py-1"><a class="text-primary-600 hover:underline" href="{{ $this->enrolmentUrl($e) }}">{{ $e->employee?->person?->full_name }} — {{ $e->course?->title }}</a> <span class="text-gray-500">{{ $e->reason }}</span></li>
                @empty
                    <li class="text-gray-500">Nothing to approve.</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section heading="Team skill gaps">
            <ul class="text-sm">
                @forelse (array_slice($this->gaps(), 0, 25) as $g)
                    <li class="py-1">{{ $g['employee'] }} — {{ $g['skill'] }}: {{ $g['label'] ?? $g['level'] ?? '—' }} → target {{ $g['target'] }} (gap {{ $g['gap'] }}{{ $g['verified'] ? '' : ', not verified' }})</li>
                @empty
                    <li class="text-gray-500">No skill gaps recorded.</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section heading="Development plans">
            <ul class="text-sm">
                @forelse ($this->plans() as $p)
                    <li class="py-1">{{ $p->employee?->person?->full_name }} — {{ $p->title }} <x-filament::badge color="gray" size="sm">{{ \App\Domain\Development\Models\DevelopmentPlan::STATUSES[$p->status] }}</x-filament::badge> <span class="text-gray-500">{{ $p->items_count - $p->open_items_count }} / {{ $p->items_count }}</span></li>
                @empty
                    <li class="text-gray-500">No plans.</li>
                @endforelse
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
