@props([
    'active' => false,
    'activeChildItems' => false,
    'activeIcon' => null,
    'badge' => null,
    'badgeColor' => null,
    'badgeTooltip' => null,
    'childItems' => [],
    'first' => false,
    'grouped' => false,
    'icon' => null,
    'last' => false,
    'shouldOpenUrlInNewTab' => false,
    'sidebarCollapsible' => true,
    'subGrouped' => false,
    'subNavigation' => false,
    'url',
])

@php
    use Filament\Support\Enums\IconSize;
    use Filament\Support\View\ComponentAttributeBag;

    $sidebarCollapsible = $sidebarCollapsible && filament()->isSidebarCollapsibleOnDesktop();

    // PeopleOS: a rail section of several areas (ExperienceNavigation marks it data-pos-section) is a button, not a
    // link: clicking it opens or folds its list of areas (rail sections in resources/js/peopleos.js). Its areas start
    // open only while one of them is the current page.
    $section = filled($childItems) ? $attributes->get('data-pos-section') : null;
    $areasId = filled($section) ? 'pos-rail-areas-'.$section : null;
    $areasOpen = $active || $activeChildItems;
    // A rail section shows its icon on a tile in its own hue (data-pos-hue) and its name in the rail's title style.
    $tile = filled($attributes->get('data-pos-hue')) && (! $subGrouped);
@endphp

<li
    {{
        $attributes->class([
            'fi-sidebar-item',
            'fi-active' => $active,
            'fi-sidebar-item-has-active-child-items' => $activeChildItems,
            'fi-sidebar-item-has-url' => filled($url),
        ])
    }}
>
    @if (filled($section))
    <button
        type="button"
        aria-expanded="{{ $areasOpen ? 'true' : 'false' }}"
        aria-controls="{{ $areasId }}"
        data-pos-rail-toggle
    @else
    <a
        {{ \Filament\Support\generate_href_html($url, $shouldOpenUrlInNewTab) }}
        @if ($active)
            aria-current="page"
        @endif
        x-on:click="window.matchMedia(`(max-width: 1024px)`).matches && $store.sidebar.close()"
    @endif
        @if ($sidebarCollapsible && (! $subNavigation))
            x-bind:aria-label="$store.sidebar.isOpen ? null : @js(trim(strip_tags($slot->toHtml())))"
            x-data="{ tooltip: false }"
            x-effect="
                tooltip = $store.sidebar.isOpen
                    ? false
                    : {
                          content: @js($slot->toHtml()),
                          placement: document.dir === 'rtl' ? 'left' : 'right',
                          theme: $store.theme,
                      }
            "
            x-tooltip.html="tooltip"
        @endif
        class="fi-sidebar-item-btn"
    >
        @if (filled($icon) && ((! $subGrouped) || ($sidebarCollapsible && (! $subNavigation))))
            @if ($tile)
                <span class="pos-rail-tile">
            @endif
            {{
                \Filament\Support\generate_icon_html((($active || $activeChildItems) && $activeIcon) ? $activeIcon : $icon, attributes: (new ComponentAttributeBag([
                    'x-show' => ($subGrouped && $sidebarCollapsible) ? '! $store.sidebar.isOpen' : false,
                ]))->class(['fi-sidebar-item-icon']), size: IconSize::Large)
            }}
            @if ($tile)
                </span>
            @endif
        @endif

        @if ((blank($icon) && $grouped) || $subGrouped)
            <div
                @if (filled($icon) && $subGrouped && $sidebarCollapsible && (! $subNavigation))
                    x-show="$store.sidebar.isOpen"
                @endif
                class="fi-sidebar-item-grouped-border"
            >
                @if (! $first)
                    <div
                        class="fi-sidebar-item-grouped-border-part-not-first"
                    ></div>
                @endif

                @if (! $last)
                    <div
                        class="fi-sidebar-item-grouped-border-part-not-last"
                    ></div>
                @endif

                <div class="fi-sidebar-item-grouped-border-part"></div>
            </div>
        @endif

        <span
            @if ($sidebarCollapsible && (! $subNavigation))
                x-show="$store.sidebar.isOpen"
                x-transition:enter="fi-transition-enter"
                x-transition:enter-start="fi-transition-enter-start"
                x-transition:enter-end="fi-transition-enter-end"
            @endif
            class="fi-sidebar-item-label"
        >
            @if ($tile)
                <span class="pos-rail-title">{{ $slot }}</span>
            @else
                {{ $slot }}
            @endif
        </span>

        @if (filled($badge))
            <span
                @if ($sidebarCollapsible && (! $subNavigation))
                    x-show="$store.sidebar.isOpen"
                    x-transition:enter="fi-transition-enter"
                    x-transition:enter-start="fi-transition-enter-start"
                    x-transition:enter-end="fi-transition-enter-end"
                @endif
                class="fi-sidebar-item-badge-ctn"
            >
                <x-filament::badge
                    :color="$badgeColor"
                    :tooltip="$badgeTooltip"
                >
                    {{ $badge }}
                </x-filament::badge>
            </span>
        @endif

        {{-- The toggle: a small orb whose plus turns into a minus as the areas open (CSS draws it). --}}
        @if (filled($section))
            <span
                @if ($sidebarCollapsible)
                    x-show="$store.sidebar.isOpen"
                @endif
                class="pos-rail-orb"
                aria-hidden="true"
            ></span>
        @endif
    @if (filled($section))
    </button>
    @else
    </a>
    @endif

    {{-- PeopleOS: Filament renders a section's areas only while the section is current. Every section's areas are in the
         page here, folded (data-pos-rail-closed) unless the current page is one of them, so clicking the section opens
         them. These, the section button above (its tile, title and orb) are the only changes from Filament 5.8's
         sidebar/item.blade.php. --}}
    @if ($childItems)
        <ul
            class="fi-sidebar-sub-group-items"
            @if ($areasId)
                id="{{ $areasId }}"
            @endif
            @unless ((blank($section) && blank($url)) || $areasOpen)
                data-pos-rail-closed
            @endunless
        >
            @foreach ($childItems as $childItem)
                @php
                    $isChildItemChildItemsActive = $childItem->isChildItemsActive();
                    $isChildActive = (! $isChildItemChildItemsActive) && $childItem->isActive();
                    $childItemActiveIcon = $childItem->getActiveIcon();
                    $childItemBadge = $childItem->getBadge();
                    $childItemBadgeColor = $childItem->getBadgeColor($childItemBadge);
                    $childItemBadgeTooltip = $childItem->getBadgeTooltip($childItemBadge);
                    $childItemIcon = $childItem->getIcon();
                    $shouldChildItemOpenUrlInNewTab = $childItem->shouldOpenUrlInNewTab();
                    $childItemUrl = $childItem->getUrl();
                    $childItemExtraAttributes = $childItem->getExtraAttributeBag();
                @endphp

                <x-filament-panels::sidebar.item
                    :active="$isChildActive"
                    :active-child-items="$isChildItemChildItemsActive"
                    :active-icon="$childItemActiveIcon"
                    :badge="$childItemBadge"
                    :badge-color="$childItemBadgeColor"
                    :badge-tooltip="$childItemBadgeTooltip"
                    :first="$loop->first"
                    grouped
                    :icon="$childItemIcon"
                    :last="$loop->last"
                    :should-open-url-in-new-tab="$shouldChildItemOpenUrlInNewTab"
                    sub-grouped
                    :sub-navigation="$subNavigation"
                    :url="$childItemUrl"
                    :attributes="\Filament\Support\prepare_inherited_attributes($childItemExtraAttributes)"
                >
                    {{ $childItem->getLabel() }}
                </x-filament-panels::sidebar.item>
            @endforeach
        </ul>
    @endif
</li>
