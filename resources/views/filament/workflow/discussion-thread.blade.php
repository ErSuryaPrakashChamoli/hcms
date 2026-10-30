{{-- HROne setup parity P3: a request's discussion, oldest first (TaskComments). --}}
@if ($comments->isEmpty())
    <p class="text-sm text-gray-500">No messages yet.</p>
@else
    <ul class="space-y-2" role="list">
        @foreach ($comments as $entry)
            <li>
                <p class="text-sm text-gray-500">{{ $entry->author?->name ?? 'Someone' }} · {{ $entry->created_at?->format('d M Y H:i') }}</p>
                <p class="text-sm whitespace-pre-line">{{ $entry->body }}</p>
            </li>
        @endforeach
    </ul>
@endif
