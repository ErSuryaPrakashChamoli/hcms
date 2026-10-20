@props(['tone' => 'indigo', 'icon' => 'heroicon-o-sparkles', 'size' => 'md'])
<span {{ $attributes->class(['pos-tile-icon', 'pos-tile-icon-'.$size]) }} data-tone="{{ $tone }}" aria-hidden="true"><x-filament::icon :icon="$icon" class="size-5" /></span>
