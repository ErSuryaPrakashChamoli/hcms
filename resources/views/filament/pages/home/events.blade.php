{{-- HROne parity GO-15 (wall card "Upcoming Events"): the person's next event invitations, answered here or on My events. --}}
@if ($h['events'] ?? null)
    @php($ev = $h['events'])
    <x-pos.section title="Upcoming events" sub="Events you are invited to." :link="$ev['url']" link-label="My events">
        <div class="pos-panel pos-stream">
            @foreach ($ev['rows'] as $row)
                @php($invitation = $row['invitation'])
                <div class="pos-stream-row" wire:key="event-{{ $invitation->id }}">
                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-calendar-days" class="size-4" /></span>
                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $row['event']->title }}</span><span class="pos-stream-meta">{{ $row['when'] }} · {{ $row['place'] }}@if ($row['stats']) · {{ $row['stats']['seats_left'] }} {{ $row['stats']['seats_left'] === 1 ? 'seat' : 'seats' }} left @endif</span></span>
                    @if ($invitation->status === 'accepted')
                        <x-pos.status tone="success" :label="$row['event']->is_mandatory ? 'Acknowledged' : 'Attending'" />
                    @elseif ($row['event']->is_mandatory)
                        <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="eventRespond({{ $invitation->id }}, 'accepted')" data-pos-action="event-acknowledge">Acknowledge</button>
                    @else
                        <span class="flex gap-1">
                            <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="eventRespond({{ $invitation->id }}, 'accepted')" data-pos-action="event-accept">Attend</button>
                            <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="eventRespond({{ $invitation->id }}, 'declined')" data-pos-action="event-decline">Decline</button>
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
    </x-pos.section>
@endif
