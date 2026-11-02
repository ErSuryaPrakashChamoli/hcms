{{-- HROne parity GO-15 (`app-events-detail`): an event as its invitee sees it. --}}
<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-2 gap-3">
        <div><dt class="text-gray-500">Type</dt><dd>{{ $event->typeLabel() ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">Sub-type</dt><dd>{{ $event->subTypeLabel() ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">Mode</dt><dd>{{ $event->modeLabel() }}</dd></div>
        <div><dt class="text-gray-500">Location</dt><dd>{{ $event->placeLabel() }}</dd></div>
        <div><dt class="text-gray-500">Host name</dt><dd>{{ $event->host?->person?->full_name ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">Number of seats</dt><dd>{{ $event->is_mandatory ? 'Mandatory' : ($event->seats ?? 'Unlimited') }}</dd></div>
        <div><dt class="text-gray-500">Time zone</dt><dd>{{ $event->timezone }}</dd></div>
        <div><dt class="text-gray-500">Your answer</dt><dd>{{ $invitation->statusLabel() }}</dd></div>
    </dl>
    <div>
        <p class="text-gray-500">Date and time</p>
        <ul class="list-disc ps-5">
            @foreach ($event->sessions->take(20) as $session)
                <li>{{ $session->label($event->timezone) }}</li>
            @endforeach
        </ul>
    </div>
    @if ($event->meeting_url)
        <p><span class="text-gray-500">Link:</span> <a class="text-primary-600 underline" href="{{ $event->meeting_url }}" target="_blank" rel="noopener">{{ $event->meeting_url }}</a></p>
    @endif
    @if ($event->video_url)
        <p><span class="text-gray-500">Video url:</span> <a class="text-primary-600 underline" href="{{ $event->video_url }}" target="_blank" rel="noopener">{{ $event->video_url }}</a></p>
    @endif
    @if ($event->description)
        <div><p class="text-gray-500">Description</p><p class="whitespace-pre-line">{{ $event->description }}</p></div>
    @endif
    @if ($event->agenda)
        <div><p class="text-gray-500">Agenda</p><div class="space-y-2">{!! \Illuminate\Support\Str::markdown((string) $event->agenda, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div></div>
    @endif
    @if (! empty($event->attachments))
        <div>
            <p class="text-gray-500">Attachment</p>
            <ul class="list-disc ps-5">
                @foreach (array_values($event->attachments) as $i => $path)
                    <li><a class="text-primary-600 underline" href="{{ $events->attachmentUrl($event, $i) }}" target="_blank" rel="noopener">{{ basename($path) }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif
    @if ($event->status === 'cancelled')
        <p class="text-danger-600">This event was cancelled: {{ $event->cancel_reason }}</p>
    @endif
</div>
