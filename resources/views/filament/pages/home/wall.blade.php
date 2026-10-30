{{-- HROne setup parity HP-9e: the latest posts of the social wall (festive greeting on the viewer's holidays). --}}
@if ($h['wall'] ?? null)
    @php($w = $h['wall'])
    <x-pos.section title="Social wall" :sub="$w['festive'] ? 'Happy '.$w['festive'].'!' : null" :link="$w['url']" link-label="Social wall">
        <div class="pos-panel pos-stream">
            @foreach ($w['posts'] as $post)
                <div class="pos-stream-row" wire:key="wall-{{ $post->id }}">
                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$post->meta['icon'] ?? 'heroicon-o-megaphone'" class="size-4" /></span>
                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $post->title }}</span><span class="pos-stream-meta">{{ $post->created_at?->diffForHumans() }} · {{ $post->reactions_count }} {{ $post->reactions_count === 1 ? 'reaction' : 'reactions' }} · {{ $post->comments_count }} {{ $post->comments_count === 1 ? 'comment' : 'comments' }}</span></span>
                    @if ($post->my_reaction)
                        <x-pos.status tone="success" label="Liked" />
                    @else
                        <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="wallReact({{ $post->id }})" data-pos-action="like">Like</button>
                    @endif
                </div>
            @endforeach
        </div>
    </x-pos.section>
@endif
