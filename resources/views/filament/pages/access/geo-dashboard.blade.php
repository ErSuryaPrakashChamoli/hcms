<x-filament-panels::page>
    <div class="pos-ws">
        <p class="pos-body pos-secondary">Lists for now: the map arrives with the map component of the check-in location banks.</p>
        @php($panels = $this->panels())
        @if (isset($panels['checkins']))
            <x-pos.section title="Today's check-ins with a location" :sub="$panels['checkins'] === 'manager' ? 'Your team.' : 'Everyone in your access scope.'" :count="count($this->checkins) ?: null">
                @if ($this->checkins === [])
                    <x-pos.state title="No located check-ins today" why="Web and mobile check-ins that carry a location appear here." />
                @else
                    <div class="pos-panel pos-stream">
                        @foreach ($this->checkins as $i => $row)
                            <div class="pos-stream-row" wire:key="checkin-{{ $i }}">
                                <span class="pos-stream-meta">{{ $row['time'] }}</span>
                                <div class="pos-stream-body">
                                    <p class="pos-stream-title">{{ $row['name'] }}</p>
                                    <p class="pos-stream-meta">{{ ucfirst($row['direction']) }} · {{ $row['place'] ?? 'Outside a work location' }} · {{ $row['coordinates'] }}@if ($row['status']) · {{ str_replace('_', ' ', $row['status']) }}@endif</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-pos.section>
        @endif
        @if (isset($panels['headcount']))
            <x-pos.section title="Headcount by location" :sub="$panels['headcount'] === 'manager' ? 'Your team.' : 'Everyone in your access scope.'">
                @if ($this->headcount === [])
                    <x-pos.state title="Nobody to count" />
                @else
                    <div class="pos-panel pos-stream">
                        @foreach ($this->headcount as $i => $row)
                            <div class="pos-stream-row" wire:key="hc-{{ $i }}">
                                <div class="pos-stream-body">
                                    <p class="pos-stream-title">{{ $row['location'] }}</p>
                                    <p class="pos-stream-meta">@if ($row['pin'])Work location at {{ $row['pin'] }}@else No work location set @endif</p>
                                </div>
                                <span class="pos-stream-meta">{{ $row['count'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-pos.section>
        @endif
    </div>
</x-filament-panels::page>
