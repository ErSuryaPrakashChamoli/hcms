<div x-data="{ open: false, lastFocus: null }"
    x-on:pos-drawer-open.window="lastFocus = document.activeElement; open = true"
    x-on:pos-drawer-close.window="if (open) { open = false; $wire.close(); $nextTick(() => lastFocus?.focus?.()) }"
    x-effect="document.documentElement.classList.toggle('pos-drawer-is-open', open)">
    <div x-show="open" x-cloak class="pos-overlay" x-on:click="open = false; $wire.close(); $nextTick(() => lastFocus?.focus?.())" x-transition.opacity.duration.200ms aria-hidden="true"></div>

    {{-- UX.18: a div, not an aside (ARIA does not allow role=dialog on aside) --}}
    <div x-show="open" x-cloak x-trap.noscroll.inert="open" role="dialog" aria-modal="true" aria-labelledby="pos-drawer-title"
        class="pos-drawer" :data-open="open.toString()" data-depth="{{ count($stack) }}"
        x-on:keydown.escape.prevent.stop="open = false; $wire.close(); $nextTick(() => lastFocus?.focus?.())"
        x-transition:enter="pos-drawer-in" x-transition:leave="pos-drawer-out">

        <header class="pos-drawer-header">
            @if (count($stack) > 0)
                <button type="button" class="pos-icon-btn" wire:click="back" aria-label="Back to the previous panel"><x-filament::icon icon="heroicon-m-arrow-left" class="size-5" /></button>
            @endif
            <h2 id="pos-drawer-title" class="pos-h3 flex-1 truncate">
                @switch($type)
                    @case('person') Person @break
                    @case('person-action') Start a change @break
                    @case('approval') Decision @break
                    @case('change') What changed @break
                    @case('pulse') Workforce pulse @break
                    @default Details
                @endswitch
            </h2>
            <button type="button" class="pos-icon-btn" x-on:click="open = false; $wire.close(); $nextTick(() => lastFocus?.focus?.())" aria-label="Close panel"><x-filament::icon icon="heroicon-m-x-mark" class="size-5" /></button>
        </header>

        <div class="pos-drawer-body">
            {{-- UX.18: a sheet opened by an event loads through __dispatch (not show), so the skeleton waited for a call
                 that never came and the body stayed blank while loading; it now shows for both --}}
            <div wire:loading.delay.short wire:target="show, back, __dispatch" class="w-full">
                @include('livewire.experience.skeleton', ['rows' => 3, 'title' => 'details'])
            </div>
            <div wire:loading.remove wire:target="show, back, __dispatch">
                @if ($type === 'person' || $type === 'person-action')
                    @php($p = $this->person)
                    @if ($p === null)
                        <x-pos.empty icon="heroicon-o-eye-slash" title="Not available" why="This person is not in your directory view, or the record no longer exists." />
                    @else
                        <div class="flex items-start gap-4">
                            <x-pos.avatar :name="$p['name']" size="xl" />
                            <div class="min-w-0 flex-1">
                                <p class="pos-h2">{{ $p['name'] }}</p>
                                <p class="pos-body-sm pos-secondary">{{ collect([$p['title'], $p['department']])->filter()->implode(' · ') ?: $p['code'] }}</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @if ($p['status'])<x-pos.status tone="primary" :label="$p['status']" />@endif
                                    @if ($p['location'])<span class="pos-caption pos-muted inline-flex items-center gap-1"><x-filament::icon icon="heroicon-m-map-pin" class="size-3.5" />{{ $p['location'] }}</span>@endif
                                </div>
                            </div>
                        </div>

                        @if ($type === 'person')
                            <div class="mt-5 flex flex-wrap gap-2">
                                @if ($p['profile'])<a href="{{ $p['profile'] }}" wire:navigate class="pos-btn pos-btn-primary pos-btn-sm">Open profile</a>@endif
                                @if ($p['email'])<a href="mailto:{{ $p['email'] }}" class="pos-btn pos-btn-secondary pos-btn-sm">Message</a>@endif
                                @if ($p['org'])<a href="{{ $p['org'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Org map</a>@endif
                                <button type="button" wire:click="togglePin" class="pos-btn pos-btn-ghost pos-btn-sm" aria-pressed="{{ $p['pinned'] ? 'true' : 'false' }}">
                                    <x-filament::icon :icon="$p['pinned'] ? 'heroicon-s-star' : 'heroicon-o-star'" class="size-4" />{{ $p['pinned'] ? 'Pinned' : 'Pin' }}
                                </button>
                            </div>

                            <dl class="pos-facts mt-6">
                                @if ($p['manager'])
                                    <div><dt>Manager</dt><dd><button type="button" class="pos-person-inline" wire:click="show('person', {{ $p['manager']['id'] }})"><x-pos.avatar :name="$p['manager']['name']" size="xs" />{{ $p['manager']['name'] }}</button></dd></div>
                                @endif
                                @if ($p['email'])<div><dt>Work email</dt><dd><a class="pos-link" href="mailto:{{ $p['email'] }}">{{ $p['email'] }}</a></dd></div>@endif
                                @if ($p['phone'])<div><dt>Work phone</dt><dd>{{ $p['phone'] }}</dd></div>@endif
                                @if ($p['tenure'])<div><dt>With us for</dt><dd>{{ $p['tenure'] }}</dd></div>@endif
                                <div><dt>Employee ID</dt><dd class="pos-num">{{ $p['code'] }}</dd></div>
                            </dl>

                            @if ($p['changes'] !== [])
                                <button type="button" class="pos-inline-callout mt-6 w-full" wire:click="show('person-action', {{ $p['id'] }})">
                                    <x-filament::icon icon="heroicon-m-bolt" class="size-4" /> Start a change for {{ \Illuminate\Support\Str::before($p['name'], ' ') }}
                                    <span aria-hidden="true" class="ms-auto">→</span>
                                </button>
                            @endif
                        @else
                            <x-pos.stepper class="mt-5" :steps="['Choose the change', 'Fill in', 'Review Before → After', 'Confirm']" :current="0" />
                            <p class="pos-body-sm pos-muted mt-3">Choose what changes. The form opens on {{ \Illuminate\Support\Str::before($p['name'], ' ') }}’s profile with today’s values, and shows the Before → After before you save. Every change is audited.</p>
                            <ul class="mt-4 space-y-2">
                                @forelse ($p['changes'] as $change)
                                    <li>
                                        <a href="{{ $p['profile'] }}?action={{ $change['key'] }}" wire:navigate class="pos-action-tile" data-pos-action="{{ $change['key'] }}">
                                            <span class="pos-icon-tile" aria-hidden="true"><x-filament::icon :icon="$change['icon']" class="size-5" /></span>
                                            <span class="pos-action-text"><span class="pos-body font-medium">{{ $change['label'] }}</span><span class="pos-caption pos-muted">{{ $change['hint'] }}</span></span>
                                        </a>
                                    </li>
                                @empty
                                    <li><x-pos.empty icon="heroicon-o-lock-closed" title="No changes available" why="Your role cannot change this person’s record." /></li>
                                @endforelse
                            </ul>
                        @endif
                    @endif
                @elseif ($type === 'change')
                    @php($c = $this->change)
                    @if ($c === null)
                        <x-pos.state variant="denied" title="Not available" why="This change is not in your view, or it is no longer recent." />
                    @else
                        <div class="grid gap-5">
                            <div class="grid gap-2">
                                <div class="pos-event-head" data-tone="{{ $c['tone'] }}">
                                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$c['icon']" class="size-4" /></span>
                                    <span class="pos-stream-body"><span class="pos-label">{{ $c['label'] }}</span><span class="pos-stream-meta">{{ $c['at']->format('l, j F Y') }} · {{ $c['at']->diffForHumans() }}</span></span>
                                </div>
                                <p class="pos-section-title">{{ $c['title'] }}</p>
                                @if ($c['detail'])<p class="pos-body pos-secondary">{{ $c['detail'] }}</p>@endif
                            </div>
                            @if ($c['person'])
                                <div class="pos-panel pos-panel-pad grid gap-3">
                                    <p class="pos-label">Person</p>
                                    <div class="flex items-center gap-3">
                                        <x-pos.avatar :name="$c['person']['name']" size="lg" />
                                        <div class="min-w-0"><p class="pos-stream-title">{{ $c['person']['name'] }}</p><p class="pos-stream-meta">{{ $c['person']['role'] ?: ' ' }}</p></div>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="show('person', {{ $c['person']['id'] }})">Preview</button>
                                        @if ($c['person']['profile'])
                                            <a href="{{ $c['person']['profile'] }}#journey" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Their journey</a>
                                        @endif
                                    </div>
                                </div>
                            @endif
                            <p class="pos-meta">Shown from the employee timeline, with the same visibility as their Employee 360. Sensitive categories need the matching permission.</p>
                            @if ($c['url'] && ! $c['person'])
                                <a href="{{ $c['url'] }}" wire:navigate class="pos-btn pos-btn-secondary pos-btn-sm justify-self-start">Open</a>
                            @endif
                        </div>
                    @endif
                @elseif ($type === 'pulse')
                    @php($d = $this->pulse)
                    @if ($d === null)
                        <x-pos.state variant="denied" title="Not available" why="Workforce figures need executive analytics access." />
                    @else
                        <div class="grid gap-4">
                            <div class="grid gap-1"><p class="pos-section-title">{{ $d['title'] }}</p><p class="pos-meta">{{ $d['why'] }}</p></div>
                            @if ($d['rows'] === [])
                                <x-pos.state variant="empty" size="inline" title="No one to show." why="{{ $d['aggregated'] ? 'Nothing in this period.' : 'Nothing in this period that you can see.' }}" />
                            @else
                                <div class="pos-panel pos-stream">
                                    @foreach ($d['rows'] as $row)
                                        <div class="pos-stream-row">
                                            @if ($row['person_id'])<x-pos.person :id="$row['person_id']" :name="$row['label']" size="sm" />@else<span class="pos-stream-title">{{ $row['label'] }}</span>@endif
                                            <span class="pos-stream-meta">{{ $row['detail'] }}</span>
                                            <span class="pos-stream-meta pos-num">{{ $row['date']?->format('j M') }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                @elseif ($type === 'approval')
                    @if ($done)
                        <div class="pos-done" role="status">
                            <svg class="pos-check pos-check-lg" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7" /></svg>
                            <p class="pos-h3 mt-3">{{ $done }}</p>
                            <p class="pos-body-sm pos-muted mt-1">The requester is notified. It moves to Completed in your approvals.</p>
                            <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm mt-4" x-on:click="open = false; $wire.close(); $nextTick(() => lastFocus?.focus?.())">Close</button>
                        </div>
                    @elseif ($this->approval === null)
                        <x-pos.empty icon="heroicon-o-check-circle" title="Nothing to decide" why="This item was already decided, withdrawn, or is not yours to decide." />
                    @else
                        <x-pos.approval-card :item="$this->approval" class="pos-approval-flat" />
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
