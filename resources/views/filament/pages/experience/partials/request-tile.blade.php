{{-- HROne parity RQ-1: one request type in "New request" (a link to the module's own form; a star when it can be a favourite). --}}
<li class="flex items-center gap-2" wire:key="request-{{ $star ? 'self' : 'behalf' }}-{{ $entry['key'] }}" data-request-type="{{ $entry['key'] }}">
    <a href="{{ $entry['url'] }}" class="pos-action-tile min-w-0">
        <span class="pos-icon-tile" aria-hidden="true"><x-filament::icon :icon="$entry['icon']" class="size-5" /></span>
        <span class="pos-action-text"><span class="pos-body font-medium">{{ $entry['label'] }}</span><span class="pos-caption pos-muted">{{ $entry['description'] }}</span></span>
    </a>
    @if ($star)
        <button type="button" class="pos-icon-btn shrink-0" wire:click="toggleFavourite(@js($entry['key']))"
            aria-pressed="{{ $entry['favourite'] ? 'true' : 'false' }}" aria-label="{{ $entry['favourite'] ? 'Remove '.$entry['label'].' from favourites' : 'Add '.$entry['label'].' to favourites' }}">
            <x-filament::icon :icon="$entry['favourite'] ? 'heroicon-s-star' : 'heroicon-o-star'" class="size-4" />
        </button>
    @endif
</li>
