{{-- HROne parity MS-3: the parts of this tab the employee may change — "Edit", or the change waiting with its status. --}}
@php($units = $this->editUnits($tab))
@if ($units !== [])
    <x-filament::section heading="Change your details" compact>
        @if ($blocked = $this->changesBlocked())
            <p class="text-sm text-warning-600">{{ $blocked }}</p>
        @endif
        <div class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
            @foreach ($units as $unit)
                <div class="flex flex-wrap items-center justify-between gap-2 py-2" data-edit-section="{{ $unit['section'] }}">
                    <div>
                        <span class="font-medium">{{ $unit['label'] }}</span>
                        @if ($unit['pending'])
                            @if ($unit['returned'])
                                <span class="text-warning-600">· Sent back to you: {{ $unit['note'] }}</span>
                            @else
                                <span class="text-gray-500">· Change pending since {{ $unit['pending']->created_at?->format('j M Y') }}@if ($unit['waiting'] !== []) with {{ implode(', ', $unit['waiting']) }}@endif</span>
                            @endif
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($unit['pending'])
                            {{ ($this->changeDetailsAction)(['request' => $unit['pending']->id]) }}
                            @if ($unit['returned'])
                                {{ ($this->editSectionAction)(['section' => $unit['section']]) }}
                                {{ ($this->resubmitChangeAction)(['request' => $unit['pending']->id]) }}
                            @endif
                            {{ ($this->withdrawChangeAction)(['request' => $unit['pending']->id]) }}
                        @else
                            {{ ($this->editSectionAction)(['section' => $unit['section']]) }}
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
@endif
