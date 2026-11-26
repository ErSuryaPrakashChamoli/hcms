{{--
    What confirming a wizard does (App\Filament\Support\Review\Outcome), on its Review step under the list of every
    detail entered: a header (what the card is, the outcome in one sentence, the status once), the body in the order it
    was built (problems, before → after, facts, figures, people, lines), and the small print as one-line notes.
--}}
@php
    $tone = $outcome['status']['tone'] ?? 'idle';
    $headIcon = $outcome['icon'] ?? [
        'clear' => 'heroicon-o-check-circle', 'warning' => 'heroicon-o-exclamation-triangle', 'blocked' => 'heroicon-o-x-circle',
        'pending' => 'heroicon-o-clock', 'na' => 'heroicon-o-minus-circle',
    ][$tone] ?? 'heroicon-o-arrow-right-circle';
    $statusIcon = [
        'clear' => 'heroicon-m-check-circle', 'warning' => 'heroicon-m-exclamation-triangle', 'blocked' => 'heroicon-m-x-circle',
        'pending' => 'heroicon-m-clock', 'na' => 'heroicon-m-minus-circle',
    ][$tone] ?? 'heroicon-m-arrow-right-circle';
@endphp
<section class="pos-outcome" data-tone="{{ $tone }}" aria-label="{{ $outcome['eyebrow'] }}" {{ new \Illuminate\View\ComponentAttributeBag($outcome['attributes']) }}>
    <header class="pos-outcome-head">
        <span class="pos-outcome-icon" aria-hidden="true"><x-filament::icon :icon="$headIcon" class="pos-outcome-icon-svg" /></span>
        <div class="pos-outcome-heading">
            <p class="pos-outcome-eyebrow">{{ $outcome['eyebrow'] }}</p>
            @if (filled($outcome['headline']))
                <p class="pos-outcome-headline">{{ $outcome['headline'] }}</p>
            @endif
            @if (filled($outcome['sub']))
                <p class="pos-outcome-sub">{{ $outcome['sub'] }}</p>
            @endif
        </div>
        @if ($outcome['status'])
            <span class="pos-outcome-status" data-tone="{{ $tone }}" role="status">
                <x-filament::icon :icon="$statusIcon" class="pos-outcome-status-icon" />{{ $outcome['status']['label'] }}
            </span>
        @endif
    </header>

    @if ($outcome['blocks'] !== [])
        <div class="pos-outcome-body">
            @foreach ($outcome['blocks'] as $block)
                @if ($block['type'] === 'problems')
                    <div class="pos-outcome-alert" data-tone="{{ $block['tone'] }}" @if (in_array($block['tone'], ['warning', 'blocked'], true)) role="alert" @endif>
                        <p class="pos-outcome-alert-title">{{ $block['title'] }}</p>
                        @if (filled($block['sub']))
                            <p class="pos-outcome-alert-sub">{{ $block['sub'] }}</p>
                        @endif
                        @if ($block['lines'] !== [])
                            <ul class="pos-outcome-alert-list">
                                @foreach ($block['lines'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @elseif ($block['type'] === 'compare')
                    <div class="pos-outcome-compare">
                        <div class="pos-outcome-side">
                            <p class="pos-outcome-label">{{ $block['before']['label'] }}</p>
                            @include('filament.shell.outcome-value', ['value' => $block['before']['value']])
                        </div>
                        <span class="pos-outcome-arrow" aria-hidden="true"><x-filament::icon icon="heroicon-m-arrow-long-right" class="pos-outcome-arrow-icon" /></span>
                        <div class="pos-outcome-side is-after">
                            <p class="pos-outcome-label">{{ $block['after']['label'] }}</p>
                            @include('filament.shell.outcome-value', ['value' => $block['after']['value']])
                        </div>
                    </div>
                @elseif ($block['type'] === 'facts')
                    <dl class="pos-outcome-facts">
                        @foreach ($block['rows'] as $row)
                            <div class="pos-outcome-fact">
                                <dt>{{ $row['label'] }}</dt>
                                <dd>
                                    @include('filament.shell.outcome-value', ['value' => $row['value']])
                                    @if (filled($row['hint']))
                                        <span class="pos-outcome-hint">{{ $row['hint'] }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @elseif ($block['type'] === 'figures')
                    <dl class="pos-step-stats pos-outcome-figures">
                        @foreach ($block['figures'] as $label => $figure)
                            <div class="pos-step-stat @if ($label === $block['key']) is-key @endif">
                                <dt>{{ $label }}</dt>
                                <dd>{{ $figure }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @elseif ($block['type'] === 'people')
                    <div class="pos-outcome-group">
                        <p class="pos-outcome-label">{{ $block['title'] }}</p>
                        <ul class="pos-outcome-people">
                            @foreach ($block['people'] as $person)
                                <li>
                                    <span class="pos-outcome-person">{{ $person['name'] }}</span>
                                    @if (filled($person['before']) || filled($person['after']))
                                        <span class="pos-outcome-person-change">
                                            <span class="pos-change-before">{{ $person['before'] ?: 'None' }}</span>
                                            <x-filament::icon icon="heroicon-m-arrow-long-right" class="pos-outcome-inline-arrow" />
                                            <span class="pos-change-after">{{ $person['after'] ?: 'None' }}</span>
                                        </span>
                                    @elseif (filled($person['detail']))
                                        <span class="pos-outcome-person-detail">{{ $person['detail'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                            @if ($block['more'] > 0)
                                <li class="pos-outcome-more">and {{ $block['more'] }} more</li>
                            @endif
                        </ul>
                    </div>
                @elseif ($block['type'] === 'table')
                    <div class="pos-outcome-group">
                        <p class="pos-outcome-label">{{ $block['title'] }}</p>
                        <div class="pos-outcome-table-wrap" tabindex="0" role="region" aria-label="{{ $block['title'] }}">
                            <table class="pos-outcome-table">
                                <thead>
                                    <tr>
                                        @foreach ($block['headers'] as $i => $header)
                                            <th scope="col" @class(['is-num' => in_array($i, $block['numeric'], true)])>{{ $header }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($block['rows'] as $cells)
                                        <tr>
                                            @foreach ($cells as $i => $cell)
                                                <td @class(['is-num' => in_array($i, $block['numeric'], true)])>{{ $cell }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($block['more'] > 0)
                            <p class="pos-outcome-more">and {{ $block['more'] }} more</p>
                        @endif
                    </div>
                @elseif ($block['type'] === 'text')
                    <div class="pos-outcome-text">@include('filament.shell.outcome-value', ['value' => $block['value']])</div>
                @elseif ($block['type'] === 'html')
                    <div class="pos-outcome-embed">{{ $block['html'] }}</div>
                @endif
            @endforeach
        </div>
    @endif

    @if ($outcome['notes'] !== [])
        <ul class="pos-outcome-notes">
            @foreach ($outcome['notes'] as $note)
                <li data-kind="{{ $note['kind'] }}">
                    <x-filament::icon :icon="$note['icon']" class="pos-outcome-note-icon" />
                    <span>@if (filled($note['lead']))<strong>{{ $note['lead'] }}</strong> @endif{{ $note['text'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
