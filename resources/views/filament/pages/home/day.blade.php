{{-- Your day: a timeline, with today's attendance as the first line (items already under Need attention are not repeated) --}}
@php($dayRows = $h['day'] === null ? null : collect($h['day'])->reject(fn ($d) => $attention->pluck('title')->contains($d['title']))->values()->all())
@if ($dayRows !== null)
    <x-pos.section title="Your day" :link="\App\Filament\Pages\MyWork::getUrl(['tab' => 'today'])" link-label="View all">
        <x-pos.phone-cap :total="count($dayRows) + (($h['me'] ?? null) ? 1 : 0)">
        <div class="pos-panel pos-panel-pad">
            <div class="pos-tl">
                @if ($h['me'] ?? null)
                    @php($me = $h['me'])
                    <div class="pos-tl-row" data-now data-tone="{{ $me['checked_in'] ? 'success' : 'primary' }}" x-data="{ pulse: false }" x-on:pos-success.window="pulse = true; setTimeout(() => pulse = false, 800)" :class="pulse && 'pos-success-pulse'">
                        <span class="pos-tl-time">Now</span>
                        <span class="pos-tl-dot" aria-hidden="true"></span>
                        <div class="pos-stream-body">
                            <p class="pos-stream-title">
                                @if ($me['checked_in']) Checked in at {{ $me['first_in']?->format('H:i') }}
                                @elseif ($me['last_out']) Checked out at {{ $me['last_out']->format('H:i') }}
                                @else Not checked in yet @endif
                            </p>
                            <p class="pos-stream-meta">
                                @if ($me['status'])Attendance: {{ $me['status'] }}@endif
                                @if ($me['next_leave'])@if ($me['status']) · @endif Next leave: {{ $me['next_leave']['label'] }} ({{ $me['next_leave']['status'] }})@endif
                            </p>
                        </div>
                        <div class="pos-stream-end">
                            @if ($me['checked_in'])
                                <button type="button" wire:click="punch('out')" wire:loading.attr="disabled" class="pos-btn pos-btn-secondary pos-btn-sm">Check out</button>
                            @else
                                <button type="button" wire:click="punch('in')" wire:loading.attr="disabled" class="pos-btn pos-btn-secondary pos-btn-sm">Check in</button>
                            @endif
                            @if ($me['payslip'] ?? null)
                                <a href="{{ $me['payslip']['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm" data-pos-action="payslip">Payslip</a>
                            @endif
                        </div>
                    </div>
                @endif
                @forelse ($dayRows as $d)
                    <div class="pos-tl-row" data-tone="{{ ['violet' => 'primary', 'teal' => 'info', 'sky' => 'info', 'amber' => 'warning', 'rose' => 'danger', 'emerald' => 'success'][$d['tone']] ?? 'primary' }}">
                        <span class="pos-tl-time">{{ $d['time'] }}</span>
                        <span class="pos-tl-dot" aria-hidden="true"></span>
                        <div class="pos-stream-body">
                            <p class="pos-stream-title">{{ $d['title'] }}</p>
                            @if ($d['detail'])<p class="pos-stream-meta">{{ $d['detail'] }}</p>@endif
                        </div>
                        <div class="pos-stream-end">
                            @if (($d['approval_id'] ?? null))
                                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'approval', id: @js($d['approval_id']) })">{{ $d['action'] }}</button>
                            @elseif ($d['action'] && $d['url'])
                                <a href="{{ $d['url'] }}" @if (str_starts_with($d['url'], url('/')) || str_starts_with($d['url'], '/')) wire:navigate @else target="_blank" rel="noopener" @endif class="pos-btn pos-btn-ghost pos-btn-sm" data-action="{{ $d['action'] }}">{{ $d['action'] }}</a>
                            @endif
                        </div>
                    </div>
                @empty
                    @if (! ($h['me'] ?? null))
                        <x-pos.state variant="empty" size="inline" title="Nothing scheduled in PeopleOS today." why="One-on-ones, training sessions, leave and things due today appear here." />
                    @else
                        <p class="pos-meta pos-tl-note">Nothing else is scheduled in PeopleOS today.</p>
                    @endif
                @endforelse
            </div>
        </div>
        </x-pos.phone-cap>
    </x-pos.section>
@endif
