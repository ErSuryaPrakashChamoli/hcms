<x-filament-panels::page>
    {{-- HROne parity TX-8a: Carry forward leaves — the leave year end as a preview of the year close, posted for the selected rows. --}}
    <div class="grid gap-3 text-sm md:grid-cols-3">
        <label class="flex flex-col gap-1">Leave year
            <select wire:model.live="year" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                @foreach ($this->yearOptions() as $period => $label)<option value="{{ $period }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1">Leave type
            <select wire:model.live="type" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                <option value="">Every leave type a leave policy carries</option>
                @foreach ($this->typeOptions() as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        @php($pending = $this->pendingCount())
        <div class="flex flex-col gap-1" data-pending="{{ $pending }}">
            <span>Pending actions</span>
            <a href="{{ $this->pendingUrl() }}" class="font-medium text-primary-600 hover:underline">
                {{ $pending }} {{ $pending === 1 ? 'employee' : 'employees' }} still to carry forward
            </a>
        </div>
    </div>

    @if ($this->typeOptions() === [])
        <p class="text-sm text-gray-500">No active leave policy lists a leave type for this leave year, so there is nothing to carry forward.</p>
    @endif

    @if ($this->result)
        <x-filament::section :heading="$this->result['title']" :description="count($this->result['done']).' employee(s) carried forward ('.$this->result['written'].' entries), '.count($this->result['skipped']).' already done or with nothing to carry, '.count($this->result['refused']).' refusal(s)'">
            <div class="space-y-2 text-sm" data-carry-result>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-x-mark" wire:click="clearResult">Close</x-filament::button>
                </div>
                @if ($this->result['refused'] !== [])
                    <div>
                        <p class="font-medium text-danger-600">Refused</p>
                        <ul class="list-disc space-y-1 ps-5">
                            @foreach ($this->result['refused'] as $row)
                                <li data-refused="{{ $row['employee_id'] }}">{{ $row['label'] }}: {{ $row['reason'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($this->result['done'] !== [])
                    <div>
                        <p class="font-medium text-success-600">Carried forward</p>
                        <ul class="list-disc space-y-1 ps-5">
                            @foreach ($this->result['done'] as $row)
                                <li data-done="{{ $row['employee_id'] }}">{{ $row['label'] }}: {{ $row['message'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($this->result['skipped'] !== [])
                    <div>
                        <p class="font-medium text-gray-600 dark:text-gray-300">Nothing new to post</p>
                        <ul class="list-disc space-y-1 ps-5">
                            @foreach ($this->result['skipped'] as $row)
                                <li data-skipped="{{ $row['employee_id'] }}">{{ $row['label'] }}: {{ $row['message'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
