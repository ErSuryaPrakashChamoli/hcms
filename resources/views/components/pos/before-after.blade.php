@props(['changes' => [], 'title' => 'What changes'])
{{-- Before → After: the signature change preview. Each row names the field; before is muted, after is emphasised. --}}
@if (count($changes) > 0)
    <div {{ $attributes->class(['pos-ba']) }} role="group" aria-label="{{ $title }}">
        <p class="pos-label">{{ $title }}</p>
        <dl class="pos-ba-rows">
            @foreach ($changes as $c)
                <div class="pos-ba-row">
                    <dt class="pos-caption pos-muted">{{ $c['label'] }}</dt>
                    <dd class="pos-ba-values">
                        @if (($c['before'] ?? null) !== null)
                            <span class="pos-ba-before"><span class="sr-only">from </span>{{ $c['before'] }}</span>
                            <span class="pos-ba-arrow" aria-hidden="true">→</span>
                        @endif
                        <span class="pos-ba-after"><span class="sr-only">{{ ($c['before'] ?? null) !== null ? 'to ' : '' }}</span>{{ $c['after'] }}</span>
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>
@endif
