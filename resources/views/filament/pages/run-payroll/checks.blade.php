{{--
    Pre-payroll checks, simplified: a progress header, then only what needs a look (one line each, opened in place to
    see who and why), then everything that is clear as compact chips. The long descriptions live in the opened panel
    and in the chips' titles; "as of" is said once. Opening a row is Alpine-only (no round trip: the checklist is heavy).
--}}
@php
    $items = collect($this->items() ?? []);
    $required = $this->checklistRequired();
    $canTick = (bool) auth()->user()?->can('payroll.calculate');
    $summary = \App\Domain\Payroll\Services\PayrollRunChecklist::summary($items->all());
    $total = $items->count();
    $needsLook = fn (array $i) => $i['status'] === 'blocked' || $i['needs_tick'];
    $attention = $items->filter($needsLook)->sortBy(fn (array $i) => match (true) { $i['status'] === 'blocked' => 0, ! $i['ticked'] => 1, default => 2 })->values();
    $clear = $items->reject($needsLook)->values();
    $checked = $items->filter(fn (array $i) => $i['needs_tick'] && $i['ticked'])->count();
    $done = $total - $summary['blocked'] - $summary['to_tick'];
    $pct = $total > 0 ? (int) round($done * 100 / $total) : 100;
    $tone = $summary['blocked'] > 0 ? 'blocked' : ($summary['to_tick'] > 0 ? 'warning' : 'clear');
    $headline = match ($tone) {
        'blocked' => $summary['blocked'].' '.\Illuminate\Support\Str::plural('check', $summary['blocked']).' blocked: clear '.($summary['blocked'] === 1 ? 'it' : 'them').' before the run',
        'warning' => $summary['to_tick'].' '.\Illuminate\Support\Str::plural('check', $summary['to_tick']).' to tick'.($required ? ' before the run' : ' (optional here)'),
        default => 'All clear. Ready to run payroll.',
    };
    $asOf = $items->first()['as_of'] ?? null;
    $segments = array_filter([
        'blocked' => [$summary['blocked'], 'Blocked'],
        'warning' => [$summary['to_tick'], 'To tick'],
        'checked' => [$checked, 'Checked'],
        'clear' => [$summary['pass'], 'Clear'],
        'na' => [$summary['not_applicable'], 'Not applicable'],
    ], fn (array $s) => $s[0] > 0);
@endphp

<div class="pos-prc" data-summary data-tone="{{ $tone }}">
    <div class="pos-prc-head">
        <div class="pos-prc-ring" style="--pct: {{ $pct }}" role="img" aria-label="{{ $done }} of {{ $total }} checks done">
            <span><strong>{{ $done }}</strong>/{{ $total }}</span>
        </div>
        <div class="pos-prc-head-body">
            <div class="pos-prc-head-top">
                <p class="pos-prc-headline">{{ $headline }}</p>
                @if ($asOf)
                    <button type="button" class="pos-prc-asof" wire:click="$refresh" title="Count again">
                        <x-filament::icon icon="heroicon-m-arrow-path" class="pos-prc-asof-icon" wire:loading.class="animate-spin" wire:target="$refresh" />
                        As of {{ \Illuminate\Support\Str::after($asOf, ', ') }}
                    </button>
                @endif
            </div>
            <div class="pos-prc-bar" aria-hidden="true">
                @foreach ($segments as $key => [$n])
                    <span data-tone="{{ $key }}" style="flex-grow: {{ $n }}"></span>
                @endforeach
            </div>
            <ul class="pos-prc-legend">
                @foreach ($segments as $key => [$n, $label])
                    <li data-tone="{{ $key }}"><span class="pos-prc-dot"></span>{{ $label }} <strong>{{ $n }}</strong></li>
                @endforeach
            </ul>
        </div>
    </div>

    @if ($attention->isNotEmpty())
        <section class="pos-prc-group" aria-label="Needs a look" x-data="{ open: null }">
            <h3 class="pos-prc-group-title">Needs a look <span>{{ $attention->count() }}</span></h3>
            <ul class="pos-prc-list">
                @foreach ($attention as $item)
                    @php
                        $itemTone = match (true) {
                            $item['status'] === 'blocked' => 'blocked',
                            $item['ticked'] => 'checked',
                            $item['lapsed'] => 'warning',
                            $item['status'] === 'pending_approval' => 'pending',
                            default => 'warning',
                        };
                        $meta = match (true) {
                            $item['status'] === 'blocked' => $item['module'].' · payroll cannot pay them, clear first',
                            $item['ticked'] => 'Checked by '.($item['checked_by'] ?? 'someone').' · '.$item['checked_at'],
                            $item['lapsed'] => $item['module'].' · changed since it was checked',
                            $item['status'] === 'pending_approval' => $item['module'].' · waiting for approval',
                            $item['pending'] > 0 => $item['module'].' · '.$item['pending'].' waiting for approval',
                            default => $item['module'].' · to review',
                        };
                        $link = $this->resolveLink($item['key']);
                    @endphp
                    <li class="pos-prc-item" wire:key="prc-{{ $item['key'] }}" data-check="{{ $item['key'] }}" data-status="{{ $item['status'] }}" data-tone="{{ $itemTone }}"
                        :class="open === '{{ $item['key'] }}' && 'is-open'">
                        <div class="pos-prc-row">
                            <button type="button" class="pos-prc-toggle" x-on:click="open = open === '{{ $item['key'] }}' ? null : '{{ $item['key'] }}'"
                                :aria-expanded="open === '{{ $item['key'] }}' ? 'true' : 'false'" aria-controls="prc-{{ $item['key'] }}">
                                <span class="pos-prc-tile" aria-hidden="true">
                                    @if ($item['ticked'])
                                        <x-filament::icon icon="heroicon-m-check" class="pos-prc-tile-icon" />
                                    @else
                                        {{ $item['count'] }}
                                    @endif
                                </span>
                                <span class="pos-prc-main">
                                    <span class="pos-prc-label">{{ $item['label'] }}<span class="sr-only">: {{ $item['count'] }}</span></span>
                                    <span class="pos-prc-meta">{{ $meta }}</span>
                                </span>
                                <x-filament::icon icon="heroicon-m-chevron-down" class="pos-prc-chevron" />
                            </button>
                            <div class="pos-prc-actions">
                                @if ($link)
                                    <a href="{{ $link['url'] }}" wire:navigate class="pos-prc-link">{{ $link['label'] }}<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-prc-link-icon" /></a>
                                @endif
                                @if ($item['needs_tick'] && ! $item['ticked'] && $canTick)
                                    <x-filament::button size="sm" icon="heroicon-m-check" wire:click="tick('{{ $item['key'] }}')">{{ $item['lapsed'] ? 'Check again' : 'Mark as checked' }}</x-filament::button>
                                @endif
                            </div>
                        </div>
                        <div class="pos-prc-detail" id="prc-{{ $item['key'] }}" x-show="open === '{{ $item['key'] }}'" x-collapse x-cloak>
                            <div class="pos-prc-detail-inner">
                                <p class="pos-prc-desc">
                                    {{ $item['description'] }}
                                    @if ($item['pending'] > 0) Requests waiting for approval are not paid this month unless approved before the run. @endif
                                </p>
                                <div class="pos-prc-people" tabindex="0" role="region" aria-label="{{ $item['label'] }}">
                                    @foreach ($item['rows'] as $row)
                                        <div class="pos-prc-person">
                                            <span class="pos-prc-code">{{ $row['code'] }}</span>
                                            <span class="pos-prc-name">{{ $row['name'] }}</span>
                                            <span class="pos-prc-why">{{ $row['detail'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                @if ($item['count'] > count($item['rows']))
                                    <p class="pos-prc-more">Showing {{ count($item['rows']) }} of {{ $item['count'] }}@if ($link): the rest are on <a href="{{ $link['url'] }}" wire:navigate>{{ \Illuminate\Support\Str::lcfirst(\Illuminate\Support\Str::after($link['label'], 'Open ')) }}</a>@endif.</p>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($clear->isNotEmpty())
        <section class="pos-prc-group" aria-label="All clear">
            <h3 class="pos-prc-group-title">All clear <span>{{ $clear->count() }}</span></h3>
            <ul class="pos-prc-chips">
                @foreach ($clear as $item)
                    <li wire:key="prc-{{ $item['key'] }}" data-check="{{ $item['key'] }}" data-status="{{ $item['status'] }}" data-tone="{{ $item['status'] === 'pass' ? 'clear' : 'na' }}" title="{{ $item['description'] }}">
                        <x-filament::icon :icon="$item['status'] === 'pass' ? 'heroicon-m-check-circle' : 'heroicon-m-minus-circle'" class="pos-prc-chip-icon" />
                        <span>{{ $item['label'] }}</span>
                        @if ($item['status'] !== 'pass')<span class="pos-prc-chip-note">n/a</span>@endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
