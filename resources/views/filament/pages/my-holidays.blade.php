<x-filament-panels::page>
    {{-- HROne parity MS-12: "Holidays for year, <year>" from the calendar that applies to the employee. --}}
    @php($data = $this->holidays())
    @php($lists = ['fixed' => $data['fixed'], 'restricted' => $data['restricted']])
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="text-lg font-semibold">Holidays for year, {{ $data['year'] }}</div>
            <div class="text-sm text-gray-500">You can see the list of holidays in the calendar year.@if ($data['calendar']) Calendar: {{ $data['calendar']['name'] }}.@endif</div>
        </div>
        <div class="flex flex-wrap items-end gap-4">
            <label class="text-sm">
                <span class="text-gray-500">Year</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="year">
                        @foreach ($this->years() as $y)
                            <option value="{{ $y }}">{{ $y }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            @if ($url = $this->calendarUrl())
                <x-filament::link :href="$url" size="sm">Holiday calendar view</x-filament::link>
            @endif
        </div>
    </div>

    @if ($data['calendar'] === null && $data['fixed'] === [] && $data['restricted'] === [] && $data['removed'] === [])
        <x-filament::section>
            <p class="text-sm text-gray-500">No holiday calendar applies to you for {{ $data['year'] }}. HR can help.</p>
        </x-filament::section>
    @else
        <x-filament::tabs>
            @foreach ($this::TABS as $key => $label)
                <x-filament::tabs.item :active="$tab === $key" wire:click="setTab('{{ $key }}')">{{ $label }} ({{ count($lists[$key]) }})</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <x-filament::section>
            @if ($tab === 'restricted' && $data['quota'])
                <p class="mb-3 text-sm text-gray-600" data-quota>You have chosen {{ $data['quota']['taken'] }} of {{ $data['quota']['quota'] }} restricted holidays for {{ $data['quota']['year'] }}.</p>
            @endif
            @if ($lists[$tab] === [])
                <p class="text-sm text-gray-500">{{ $tab === 'restricted' ? (($data['calendar']['requires_selection'] ?? true) ? 'No restricted holidays this year.' : 'Optional holidays on your calendar are holidays for everyone; there is nothing to choose.') : 'No fixed holidays this year.' }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-holidays="{{ $tab }}">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-1">Holiday name</th><th>Day</th><th>Date</th><th>Type</th>
                                @if ($tab === 'restricted')<th>Your choice</th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lists[$tab] as $h)
                                <tr class="border-t border-gray-200 dark:border-gray-700" data-holiday="{{ $h['id'] }}" @isset($h['change_id']) data-holiday-change="{{ $h['change_id'] }}" @endisset>
                                    <td class="py-1">{{ $h['name'] }}@if ($h['half_day']) <span class="text-xs text-gray-500">(half day)</span>@endif @isset($h['note'])<span class="text-xs text-gray-500">· {{ $h['note'] }}</span>@endisset</td>
                                    <td>{{ $h['weekday'] }}</td>
                                    <td class="whitespace-nowrap">{{ $h['date']->format('d M Y') }}</td>
                                    <td>{{ $h['type'] }}</td>
                                    @if ($tab === 'restricted')
                                        <td data-choice="{{ $h['choice'] }}">
                                            @switch($h['choice'])
                                                @case('chosen') <span class="text-success-600">Chosen</span> @break
                                                @case('requested') <span class="text-warning-600">Requested</span> @break
                                                @case('given') <span class="text-success-600">Given by HR</span> @break
                                                @default
                                                    @if ($choose = $this->chooseUrl($h))
                                                        <x-filament::link :href="$choose" size="sm">Choose</x-filament::link>
                                                    @else
                                                        <span class="text-gray-500">Not chosen</span>
                                                    @endif
                                            @endswitch
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        @if ($data['removed'] !== [])
            {{-- HROne parity TX-12: calendar holidays HR took away for this employee. --}}
            <x-filament::section heading="Not holidays for you this year">
                <ul class="space-y-1 text-sm" data-holidays-removed>
                    @foreach ($data['removed'] as $h)
                        <li data-holiday-change="{{ $h['change_id'] }}">{{ $h['name'] }} · {{ $h['weekday'] }}, {{ $h['date']->format('d M Y') }} <span class="text-xs text-gray-500">· {{ $h['note'] }}</span></li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if ($data['calendar']['note'] ?? null)
            <p class="text-sm text-gray-600" data-note><span class="font-medium">Note:</span> {{ $data['calendar']['note'] }}</p>
        @endif
    @endif
</x-filament-panels::page>
