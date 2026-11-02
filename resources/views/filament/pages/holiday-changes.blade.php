<x-filament-panels::page>
    {{-- HROne parity TX-12: Holiday changes — "Change holiday" (assign / remove / replace for chosen employees) and the changes made, with Undo. --}}
    @if ($this->result)
        <x-filament::section :heading="$this->result['title']" :description="count($this->result['done']).' employee(s) changed, '.count($this->result['refused']).' refusal(s)'">
            <div class="space-y-2 text-sm" data-holiday-result>
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
                        <p class="font-medium text-success-600">Changed</p>
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
