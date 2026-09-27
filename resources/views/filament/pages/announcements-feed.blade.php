<x-filament-panels::page>
    @forelse ($this->getFeed() as $item)
        @php($a = $item['announcement'])
        @php($read = $item['read'])
        <x-filament::section :heading="$a->title" :description="config('peopleos.communication.types.' . $a->type) . ' · ' . ($a->publish_at?->diffForHumans() ?? '') . ($a->is_pinned ? ' · pinned' : '') . ($read ? '' : ' · unread')" collapsible :collapsed="(bool) $read">
            <div class="prose prose-sm max-w-none dark:prose-invert">{!! \Illuminate\Support\Str::markdown($a->body) !!}</div>
            @if ($a->article_id)
                <div class="mt-3"><x-filament::link :href="\App\Filament\Resources\Articles\ArticleResource::getUrl('view', ['record' => $a->article_id])" size="sm">Read the full article</x-filament::link></div>
            @endif
            <div class="mt-4 flex gap-2">
                @if (! $read)
                    <x-filament::button size="sm" color="gray" wire:click="markRead({{ $a->id }})">Mark as read</x-filament::button>
                @endif
                @if ($a->requires_acknowledgement && ! $read?->acknowledged_at)
                    <x-filament::button size="sm" color="success" wire:click="acknowledge({{ $a->id }})">I acknowledge</x-filament::button>
                @elseif ($a->requires_acknowledgement)
                    <span class="text-sm text-gray-500">Acknowledged {{ $read->acknowledged_at->diffForHumans() }}</span>
                @endif
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>Nothing to read right now.</x-filament::section>
    @endforelse
</x-filament-panels::page>
