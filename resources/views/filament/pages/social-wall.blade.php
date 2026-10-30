{{-- HROne setup parity HP-9e: the social wall (SocialWall decides what the viewer sees and may do). --}}
<x-filament-panels::page>
    @php($posts = $this->posts())
    @php($comments = $this->commentsFor($posts))
    @php($moderator = auth()->user()->can('wall.moderate'))
    @forelse ($posts as $post)
        <x-filament::section wire:key="wall-post-{{ $post->id }}" :heading="$post->title"
            :description="(\App\Domain\Recognition\Models\WallPost::TYPES[$post->type] ?? $post->type).' · '.($post->author?->name ?? $post->meta['given_by'] ?? 'PeopleOS').' · '.$post->created_at?->diffForHumans().($post->pinned ? ' · pinned' : '').($post->status === 'hidden' ? ' · hidden: '.$post->hide_reason : '')"
            :icon="$post->meta['icon'] ?? 'heroicon-o-megaphone'">
            @if (filled($post->body))
                <p class="text-sm whitespace-pre-line">{{ $post->body }}</p>
            @endif
            @if (! empty($post->meta['points']))
                <div class="mt-2"><x-filament::badge color="success">{{ $post->meta['points'] }} reward points</x-filament::badge></div>
            @endif
            @if ($post->attachments && $this->attachmentUrl($post, 0))
                <div class="mt-2 flex flex-wrap gap-2 text-sm">
                    @foreach (array_values($post->attachments) as $i => $path)
                        <x-filament::link :href="$this->attachmentUrl($post, $i)" target="_blank" size="sm">{{ basename($path) }}</x-filament::link>
                    @endforeach
                </div>
            @endif

            <div class="mt-3 flex flex-wrap items-center gap-2">
                @foreach (\App\Domain\Recognition\Models\WallReaction::REACTIONS as $reaction => $label)
                    <x-filament::button size="sm" :color="$post->my_reaction === $reaction ? 'primary' : 'gray'" :icon="\App\Domain\Recognition\Models\WallReaction::ICONS[$reaction]"
                        wire:click="react({{ $post->id }}, '{{ $reaction }}')">{{ $label }}</x-filament::button>
                @endforeach
                <span class="text-sm text-gray-500">{{ $post->reactions_count }} {{ $post->reactions_count === 1 ? 'reaction' : 'reactions' }} · {{ $post->comments_count }} {{ $post->comments_count === 1 ? 'comment' : 'comments' }}</span>
            </div>

            @foreach ($comments[$post->id] ?? [] as $comment)
                <div class="mt-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700" wire:key="wall-comment-{{ $comment->id }}">
                    <span class="font-semibold">{{ $comment->user?->name }}</span>
                    <span class="text-gray-500">· {{ $comment->created_at?->diffForHumans() }}{{ $comment->status === 'hidden' ? ' · hidden: '.$comment->hide_reason : '' }}</span>
                    <p class="mt-1 whitespace-pre-line">{{ $comment->status === 'removed' ? 'Removed by its author.' : $comment->body }}</p>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @if ((int) $comment->user_id === (int) auth()->id() && $comment->status === 'published')
                            <x-filament::link size="sm" color="gray" tag="button" wire:click="removeComment({{ $comment->id }})">Remove</x-filament::link>
                        @elseif ($comment->status === 'published')
                            <x-filament::link size="sm" color="gray" tag="button" wire:click="mountAction('report', { comment: {{ $comment->id }} })">Report</x-filament::link>
                        @endif
                        @if ($moderator && $comment->status === 'published')
                            <x-filament::link size="sm" color="danger" tag="button" wire:click="mountAction('hide', { comment: {{ $comment->id }} })">Hide</x-filament::link>
                        @elseif ($moderator && $comment->status === 'hidden')
                            <x-filament::link size="sm" tag="button" wire:click="mountAction('restore', { comment: {{ $comment->id }} })">Restore</x-filament::link>
                        @endif
                    </div>
                </div>
            @endforeach

            <div class="mt-3 flex flex-wrap gap-2">
                @if ($post->status === 'published' && $this->commentsAllowed($post))
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-chat-bubble-left" wire:click="mountAction('comment', { post: {{ $post->id }} })">Comment</x-filament::button>
                @endif
                @if ($post->status === 'published')
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-flag" wire:click="mountAction('report', { post: {{ $post->id }} })">Report</x-filament::button>
                @endif
                @if ($moderator)
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-bookmark" wire:click="pin({{ $post->id }}, {{ $post->pinned ? 'false' : 'true' }})">{{ $post->pinned ? 'Unpin' : 'Pin' }}</x-filament::button>
                    @if ($post->status === 'published')
                        <x-filament::button size="sm" color="danger" icon="heroicon-o-eye-slash" wire:click="mountAction('hide', { post: {{ $post->id }} })">Hide</x-filament::button>
                    @else
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-eye" wire:click="mountAction('restore', { post: {{ $post->id }} })">Restore</x-filament::button>
                    @endif
                @endif
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>Nothing on the wall yet. Badges, celebrations, announcements and new joiners appear here.</x-filament::section>
    @endforelse
    @if ($posts->count() >= $this->limit)
        <div><x-filament::button color="gray" wire:click="loadMore">Show more</x-filament::button></div>
    @endif
</x-filament-panels::page>
