@props(['journey'])
@php
    $stages = $journey['stages'] ?? [];
    $currentKey = $journey['current'] ?? null;
@endphp
{{-- Journey map: stages with done / current / upcoming states; selecting a stage reveals its events. --}}
@if ($stages !== [])
    <div {{ $attributes->class(['pos-journey']) }} x-data="{ open: @js($currentKey) }" id="journey">
        <div class="pos-journey-track" role="tablist" aria-label="Journey stages">
            @foreach ($stages as $stage)
                <div class="pos-journey-stage" role="presentation" data-state="{{ $stage['state'] }}" style="--i: {{ $loop->index }}">
                    <button type="button" role="tab" class="pos-journey-btn" :aria-selected="(open === @js($stage['key'])).toString()" aria-controls="journey-{{ $stage['key'] }}"
                        x-on:click="open = @js($stage['key'])">
                        <span class="pos-journey-dot" aria-hidden="true">
                            @if ($stage['state'] === 'done')<x-filament::icon icon="heroicon-m-check" class="size-3.5" />@endif
                        </span>
                        <span class="pos-journey-label">{{ $stage['label'] }}</span>
                        <span class="sr-only">({{ ['done' => 'completed', 'current' => 'current stage', 'upcoming' => 'not reached', 'skipped' => 'skipped'][$stage['state']] ?? $stage['state'] }})</span>
                        @if ($stage['date'])<span class="pos-journey-date">{{ $stage['date']->format('M Y') }}</span>@endif
                    </button>
                </div>
            @endforeach
        </div>
        @foreach ($stages as $stage)
            <section id="journey-{{ $stage['key'] }}" role="tabpanel" class="pos-journey-panel" x-show="open === @js($stage['key'])" x-cloak>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="pos-h3">{{ $stage['label'] }}
                        @if ($stage['state'] === 'current')<x-pos.status tone="primary" label="Now" class="ms-2" />@endif
                    </h3>
                    @if ($stage['summary'])<p class="pos-body-sm">{{ $stage['summary'] }}</p>@endif
                </div>
                @if ($stage['events'] === [])
                    <p class="pos-caption mt-2">{{ $stage['state'] === 'upcoming' ? 'Not reached yet.' : ($stage['state'] === 'skipped' ? 'This stage did not apply.' : 'No recorded events you can see in this stage.') }}</p>
                @else
                    <ol class="pos-timeline mt-3">
                        @foreach ($stage['events'] as $event)
                            <li class="pos-timeline-row">
                                <span class="pos-timeline-node" aria-hidden="true"></span>
                                <div class="min-w-0">
                                    <p class="pos-body">{{ $event['title'] }}</p>
                                    <p class="pos-caption">{{ $event['category'] }}@if ($event['date']) · {{ $event['date']->format('d M Y') }}@endif</p>
                                    @if ($event['detail'])<p class="pos-body-sm mt-0.5">{{ \Illuminate\Support\Str::limit($event['detail'], 220) }}</p>@endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        @endforeach
    </div>
@endif
