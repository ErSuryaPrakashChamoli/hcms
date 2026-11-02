<x-filament-panels::page>
    {{-- HROne parity TX-5: the roster board — employees × days of a week or a month, the resolved shift and the day's marks. --}}
    <div class="flex flex-wrap items-center gap-3 text-sm" data-roster-window="{{ $this->period }}|{{ $this->start }}">
        <label class="flex flex-col gap-1">Show
            <select wire:model.live="period" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                <option value="week">A week</option>
                <option value="month">A month</option>
            </select>
        </label>
        <label class="flex flex-col gap-1">From
            <input type="date" wire:model.live="start" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
        </label>
        <div class="flex items-center gap-2">
            <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-left" wire:click="previous">Previous</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="today">Today</x-filament::button>
            <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-right" icon-position="after" wire:click="next">Next</x-filament::button>
        </div>
    </div>

    @if ($this->result)
        <x-filament::section :heading="$this->result['title']" :description="count($this->result['done']).' employee(s) done, '.count($this->result['refused']).' refused · '.$this->result['days'].' day(s) changed'">
            <div class="space-y-2 text-sm" data-roster-result>
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
                        <p class="font-medium text-success-600">Done</p>
                        <ul class="list-disc space-y-1 ps-5">
                            @foreach ($this->result['done'] as $row)
                                <li data-done="{{ $row['employee_id'] }}">{{ $row['label'] }}: {{ $row['message'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
