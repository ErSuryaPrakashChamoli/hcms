@props(['changes' => [], 'title' => 'Before → After', 'effective' => null])
{{--
    PeopleChange: the signature change preview. Each row names the field; before is struck through, after
    is emphasised; the effective date says from when. Used in forms, approvals and drawers alike.
--}}
@if (count($changes) > 0)
    <div {{ $attributes->class(['pos-change']) }} role="group" aria-label="{{ $title }}">
        <div class="pos-change-head">
            <p class="pos-change-label">{{ $title }}</p>
            @if ($effective)
                <span class="pos-change-when"><x-filament::icon icon="heroicon-m-calendar" class="size-3.5" />Effective {{ $effective instanceof \Carbon\CarbonInterface ? $effective->format('j M Y') : $effective }}</span>
            @endif
        </div>
        <dl class="pos-change-rows">
            @foreach ($changes as $c)
                @php($hasBefore = ($c['before'] ?? null) !== null && ($c['before'] ?? null) !== '')
                <div class="pos-change-row" @if ($hasBefore && (string) $c['before'] === (string) $c['after']) data-unchanged @endif>
                    <dt class="pos-change-field">{{ $c['label'] }}</dt>
                    <dd class="pos-change-values">
                        @if ($hasBefore)
                            <span class="pos-change-before"><span class="sr-only">from </span>{{ $c['before'] }}</span>
                            <span class="pos-change-arrow" aria-hidden="true">→</span>
                        @endif
                        <span class="pos-change-after"><span class="sr-only">{{ $hasBefore ? 'to ' : '' }}</span>{{ $c['after'] }}</span>
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>
@endif
