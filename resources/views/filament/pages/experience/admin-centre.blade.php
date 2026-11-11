<x-filament-panels::page>
    @php
        $g = $this->governance;
        $categories = $this->categories;
        $screens = array_sum(array_map(fn (array $cat): int => count($cat['modules']), $categories));
        $eyebrow = 'Admin Centre'.($screens > 0 ? ' · '.$screens.' '.\Illuminate\Support\Str::plural('screen', $screens).' in '.count($categories).' '.\Illuminate\Support\Str::plural('area', count($categories)) : '');
        // Governance at a glance: one row per figure the viewer may see (null = no access to the screen behind it).
        $glance = array_values(array_filter([
            $g['pending_count'] !== null ? ['label' => 'Changes awaiting approval', 'value' => $g['pending_count'], 'icon' => 'heroicon-o-clipboard-document-check',
                'tone' => $g['pending_count'] > 0 ? 'warning' : 'success', 'meta' => $g['pending_count'] > 0 ? 'Review in the Change Centre' : 'Nothing waiting', 'href' => $g['links']['config'] ?? null] : null,
            $g['dead_letters'] !== null ? ['label' => 'Integration messages to fix', 'value' => $g['dead_letters'], 'icon' => 'heroicon-o-arrows-right-left',
                'tone' => $g['dead_letters'] > 0 ? 'danger' : 'success', 'meta' => $g['dead_letters'] > 0 ? 'Open the inbound events' : 'Every message delivered', 'href' => $g['links']['integrations'] ?? null] : null,
            $g['failed_jobs'] !== null ? ['label' => 'Failed background jobs', 'value' => $g['failed_jobs'], 'icon' => 'heroicon-o-cpu-chip',
                'tone' => $g['failed_jobs'] > 0 ? 'danger' : 'success', 'meta' => $g['failed_jobs'] > 0 ? 'Check platform readiness' : 'Queues are healthy', 'href' => $g['links']['readiness'] ?? null] : null,
        ]));
        $attention = count(array_filter($glance, fn (array $row): bool => $row['value'] > 0));
    @endphp

    <div class="pos-ws pos-adm">
        {{-- The hero: what this is, the finder (plain words in, the screen that controls it out, only screens this viewer
             may open) and governance at a glance. The artwork is decoration only. --}}
        <header class="pos-adm-hero" @if ($glance === []) data-solo @endif>
            <div class="pos-adm-hero-art" aria-hidden="true"></div>

            <div class="pos-adm-hero-main">
                <div class="pos-ws-head-text">
                    <p class="pos-ws-eyebrow pos-adm-eyebrow">
                        <span class="pos-adm-mark"><x-filament::icon icon="heroicon-m-squares-plus" class="size-4" /></span>
                        <span>{{ $eyebrow }}</span>
                    </p>
                    <h1 class="pos-ws-title pos-ws-title-display">{{ $this->getHeading() }}</h1>
                    <p class="pos-ws-brief">{{ $this->getSubheading() }}</p>
                </div>

                <div class="pos-adm-find" x-data="{ open: true }" x-on:click.outside="open = false" x-on:focusin="open = true" x-on:keydown.escape="open = false">
                    <label class="pos-adm-search">
                        <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-5" />
                        <span class="sr-only">Find a setting</span>
                        <input type="search" wire:model.live.debounce.200ms="find" placeholder="Find a setting in plain words…" autocomplete="off" spellcheck="false" />
                        <x-filament::loading-indicator wire:loading wire:target="find" class="size-4 pos-muted" />
                    </label>

                    @if (trim($find) !== '')
                        <div class="pos-adm-results" x-show="open" x-transition.opacity.duration.150ms>
                            @if ($this->matches === [])
                                <x-pos.state variant="filtered" size="inline" title="Nothing matches “{{ $find }}”." why="Try another word, or choose an area below. Only settings you have access to are shown." />
                            @else
                                <p class="pos-adm-results-head" aria-live="polite">{{ count($this->matches) }} best {{ count($this->matches) === 1 ? 'match' : 'matches' }}</p>
                                <div class="pos-stream" role="list" aria-label="Best matches">
                                    @foreach ($this->matches as $m)
                                        <a href="{{ $m['url'] }}" wire:navigate class="pos-stream-row" role="listitem">
                                            <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-adjustments-horizontal" class="size-4" /></span>
                                            <span class="pos-stream-body"><span class="pos-stream-title">{{ $m['label'] }}</span><span class="pos-stream-meta">{{ $m['hint'] }}</span></span>
                                            <x-filament::icon icon="heroicon-m-arrow-right" class="pos-adm-go size-4" />
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="pos-adm-try">
                        <span class="pos-adm-try-label">Try</span>
                        @foreach ($this::SUGGESTIONS as $suggestion)
                            <button type="button" class="pos-adm-chip" wire:click="$set('find', @js($suggestion))" x-on:click="open = true">{{ $suggestion }}</button>
                        @endforeach
                    </div>
                </div>
            </div>

            @if ($glance !== [])
                <aside class="pos-adm-glance" aria-label="Governance">
                    <p class="pos-adm-glance-head">
                        <span>At a glance</span>
                        <span class="pos-adm-glance-state" data-tone="{{ $attention > 0 ? 'warning' : 'success' }}">
                            {{ $attention > 0 ? $attention.' '.($attention === 1 ? 'needs' : 'need').' attention' : 'All clear' }}
                        </span>
                    </p>
                    <ul class="pos-adm-stats">
                        @foreach ($glance as $row)
                            <li>
                                <{{ $row['href'] ? 'a' : 'div' }} @if ($row['href']) href="{{ $row['href'] }}" wire:navigate @endif class="pos-adm-stat" data-tone="{{ $row['tone'] }}">
                                    <span class="pos-adm-stat-icon" aria-hidden="true"><x-filament::icon :icon="$row['icon']" class="size-5" /></span>
                                    <span class="pos-adm-stat-body">
                                        <span class="pos-adm-stat-label">{{ $row['label'] }}</span>
                                        <span class="pos-adm-stat-meta">{{ $row['meta'] }}</span>
                                    </span>
                                    <span class="pos-adm-stat-value">{{ $row['value'] }}</span>
                                </{{ $row['href'] ? 'a' : 'div' }}>
                            </li>
                        @endforeach
                    </ul>
                </aside>
            @endif
        </header>

        {{-- What is waiting and what changed lately: only when there is something to show. --}}
        @php($pending = $g['pending'] ?? collect())
        @php($recent = $g['recent'] ?? collect())
        @if ($pending->isNotEmpty() || $recent->isNotEmpty())
            <div class="pos-adm-activity">
                @if ($pending->isNotEmpty())
                    <x-pos.section title="Awaiting approval" :count="$g['pending_count']" :link="$g['links']['config'] ?? null" link-label="Change Centre">
                        <div class="pos-panel pos-stream">
                            @foreach ($pending as $change)
                                <div class="pos-stream-row" data-tone="{{ in_array($change->risk_level?->value, ['high', 'critical'], true) ? 'danger' : 'warning' }}">
                                    <span class="pos-stream-mark" aria-hidden="true"></span>
                                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $change->subject_label ?? \Illuminate\Support\Str::headline(class_basename((string) $change->subject_type)) }}</span>
                                        <span class="pos-stream-meta">{{ \Illuminate\Support\Str::headline((string) $change->change_type) }} · {{ $change->risk_level?->name ?? 'Risk not set' }} risk{{ $change->effective_from ? ' · from '.$change->effective_from->format('j M') : '' }}</span></span>
                                    <span></span>
                                </div>
                            @endforeach
                        </div>
                    </x-pos.section>
                @endif

                @if ($recent->isNotEmpty())
                    <x-pos.section title="Recently changed">
                        <div class="pos-panel pos-stream">
                            @foreach ($recent as $change)
                                <div class="pos-stream-row" data-tone="success">
                                    <span class="pos-stream-mark" aria-hidden="true"></span>
                                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $change->subject_label ?? \Illuminate\Support\Str::headline(class_basename((string) $change->subject_type)) }}</span>
                                        <span class="pos-stream-meta">{{ \Illuminate\Support\Str::headline((string) $change->change_type) }} · {{ $change->published_at?->diffForHumans() }}</span></span>
                                    <span></span>
                                </div>
                            @endforeach
                        </div>
                    </x-pos.section>
                @endif
            </div>
        @endif

        {{-- The areas down the left, the open one across the rest of the page (full width: two or three columns). --}}
        <x-pos.section title="Manage" sub="Choose an area to see everything in it.">
            <x-pos.browse label="Areas to manage" remember="admin-centre"
                :groups="array_map(fn (array $cat) => ['key' => $cat['key'], 'label' => $cat['label'], 'sub' => $cat['why'], 'icon' => $cat['icon'], 'items' => $cat['modules']], $categories)" />
        </x-pos.section>
    </div>
</x-filament-panels::page>
