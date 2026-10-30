{{-- HROne setup parity HP-9a: the messages employees receive, filled in for the viewer (Birthdays::previewMessages). --}}
<div class="pos-panel pos-stream">
    @forelse ($messages as $message)
        <div class="pos-stream-row">
            <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-chat-bubble-left-ellipsis" class="size-4" /></span>
            <div class="pos-stream-body">
                <p class="pos-stream-title">{{ $message['label'] }}</p>
                <p class="pos-body whitespace-pre-line">{{ $message['text'] }}</p>
            </div>
            <span></span>
        </div>
    @empty
        <x-pos.state title="No messages are switched on" why="Turn on a greeting in Set up or Setup → Engage." />
    @endforelse
</div>
