@props(['rows' => [], 'title' => 'At a glance', 'label' => null])
{{--
    PeopleGlance: the glass "At a glance" card that sits in a masthead. One ruled row per figure: a toned icon, a label,
    one line of meaning and the value. The head says once whether anything needs attention (warning or danger rows
    added up). A row may link (`href`) or call the page (`click`, a Livewire expression); else it is text.
    Row: ['label' => string, 'value' => int|string, 'icon' => string, 'tone' => success|warning|danger|info|neutral,
          'meta' => ?string, 'href' => ?string, 'click' => ?string]
--}}
@php
    // The head's state: how many items need attention in all (the values of the warning and danger rows together).
    $attention = array_sum(array_map(fn (array $r): int => in_array($r['tone'] ?? null, ['warning', 'danger'], true) ? max(0, (int) ($r['value'] ?? 0)) : 0, $rows));
    $rows = array_map(fn (array $r): array => $r + ['tag' => ($r['href'] ?? null) ? 'a' : (($r['click'] ?? null) ? 'button' : 'div')], $rows);
@endphp
<aside {{ $attributes->class(['pos-glance']) }} aria-label="{{ $label ?? $title }}">
    <p class="pos-glance-head">
        <span>{{ $title }}</span>
        <span class="pos-glance-state" data-tone="{{ $attention > 0 ? 'warning' : 'success' }}">
            {{ $attention > 0 ? $attention.' '.($attention === 1 ? 'needs' : 'need').' attention' : 'All clear' }}
        </span>
    </p>
    <ul class="pos-glance-rows">
        @foreach ($rows as $row)
            <li>
                <{{ $row['tag'] }} class="pos-glance-row" data-tone="{{ $row['tone'] ?? 'neutral' }}"
                    @if ($row['tag'] === 'a') href="{{ $row['href'] }}" wire:navigate @elseif ($row['tag'] === 'button') type="button" wire:click="{{ $row['click'] }}" @endif>
                    <span class="pos-glance-icon" aria-hidden="true"><x-filament::icon :icon="$row['icon'] ?? 'heroicon-o-square-3-stack-3d'" class="size-5" /></span>
                    <span class="pos-glance-body">
                        <span class="pos-glance-label">{{ $row['label'] }}</span>
                        @if ($row['meta'] ?? null)<span class="pos-glance-meta">{{ $row['meta'] }}</span>@endif
                    </span>
                    <span class="pos-glance-value">{{ is_int($row['value']) ? number_format($row['value']) : $row['value'] }}</span>
                </{{ $row['tag'] }}>
            </li>
        @endforeach
    </ul>
</aside>
