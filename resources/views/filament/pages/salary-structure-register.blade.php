<x-filament-panels::page>
    {{-- HROne parity PO-2: employees and their salary structures (matrix 25 §25.2.1). The cards count the whole register in your access. --}}
    @php($counts = $this->stateCounts())
    <div class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-5" data-state-cards>
        @foreach (\App\Domain\Compensation\Services\SalaryAssignments::STATES as $key => $label)
            <button type="button" wire:click="showState('{{ $key }}')" class="rounded-lg border border-gray-200 p-3 text-start dark:border-gray-700" data-state="{{ $key }}">
                <span class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ $label }}</span>
                    <span class="tabular-nums font-semibold {{ in_array($key, ['not_assigned', 'rejected'], true) && $counts[$key] > 0 ? 'text-warning-600' : 'text-gray-500' }}">{{ $counts[$key] }}</span>
                </span>
                <span class="mt-2 block text-xs text-gray-500">{{ match ($key) {
                    'assigned' => 'Approved salary in force today',
                    'not_assigned' => 'No salary in force and no request open',
                    'pending' => 'A request is waiting (draft, submitted or under review)',
                    'approved' => 'Approved, waiting to take effect',
                    default => 'The latest request was rejected',
                } }}</span>
            </button>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
