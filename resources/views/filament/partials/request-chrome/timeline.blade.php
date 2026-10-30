{{-- HROne parity RQ-2: a request's trail (read only) and, while it is pending, whom it waits with. --}}
@php
    /** @var array $trail */
    $tones = ['success' => 'success', 'danger' => 'danger', 'warning' => 'warning', 'info' => 'info', 'neutral' => 'neutral'];
@endphp
<div data-request-timeline>
    @if ($trail['pending'])
        <p class="pos-body-sm" data-waiting>
            <span class="font-semibold">Waiting with:</span>
            {{ $trail['waiting'] !== [] ? implode(', ', $trail['waiting']) : ($fallback ?? 'the approvers') }}
            @if ($trail['due'])<span class="pos-caption pos-muted">· due {{ $trail['due']->format('d M Y, H:i') }}{{ $trail['due']->isPast() ? ' (overdue)' : '' }}</span>@endif
        </p>
    @endif
    <ol class="pos-stream mt-2" aria-label="Request trail">
        @foreach ($trail['entries'] as $entry)
            <li class="pos-stream-row" data-tone="{{ $tones[$entry['tone']] ?? 'info' }}" data-kind="{{ $entry['kind'] }}">
                <span class="pos-stream-mark" aria-hidden="true"></span>
                <div class="pos-stream-body">
                    <p class="pos-stream-title">{{ $entry['title'] }}</p>
                    <p class="pos-stream-meta">
                        @if ($entry['by']){{ $entry['by'] }} · @endif
                        <time datetime="{{ $entry['at']->toIso8601String() }}">{{ $entry['at']->format('d M Y, H:i') }}</time>
                    </p>
                    @if ($entry['note'])<p class="pos-body-sm whitespace-pre-line">“{{ $entry['note'] }}”</p>@endif
                </div>
            </li>
        @endforeach
    </ol>
</div>
