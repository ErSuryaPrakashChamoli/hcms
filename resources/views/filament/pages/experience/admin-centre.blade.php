<x-filament-panels::page>
    @php($g = $this->governance)
    <div class="pos-ws-cols">
        <div class="pos-ws-main">
            {{-- Find a setting: plain words in, the screen that controls it out (only screens this viewer may open). --}}
            <x-pos.section title="Find a setting">
                <label class="pos-search pos-search-lg" style="max-width: none">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-5 pos-muted" />
                    <span class="sr-only">Find a setting</span>
                    <input type="search" wire:model.live.debounce.200ms="find" placeholder="For example “leave policy”, “probation period”, “who can approve” or “single sign-on”" autocomplete="off" />
                </label>
                @if (trim($find) !== '')
                    @if ($this->matches === [])
                        <x-pos.state variant="filtered" size="inline" title="Nothing matches “{{ $find }}”." why="Try another word, or choose an area below. Only settings you have access to are shown." />
                    @else
                        <div class="pos-panel pos-stream" role="list" aria-label="Best matches">
                            @foreach ($this->matches as $m)
                                <a href="{{ $m['url'] }}" wire:navigate class="pos-stream-row" role="listitem">
                                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-adjustments-horizontal" class="size-4" /></span>
                                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $m['label'] }}</span><span class="pos-stream-meta">{{ $m['hint'] }}</span></span>
                                    <span aria-hidden="true" class="pos-muted">→</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endif
            </x-pos.section>

            <x-pos.section title="Manage" sub="Choose what you want to change. Each area opens its most-used settings; everything else is one click away.">
                <div class="pos-manage-grid">
                    @foreach ($this->categories as $cat)
                        <div class="pos-panel pos-panel-pad pos-manage" x-data="{ all: false }">
                            <div class="pos-manage-head">
                                <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$cat['icon']" class="size-4" /></span>
                                <div class="min-w-0">
                                    <h3 class="pos-stream-title font-semibold">{{ $cat['label'] }}</h3>
                                    <p class="pos-stream-meta">{{ $cat['why'] }}</p>
                                </div>
                            </div>
                            <ul class="pos-manage-list">
                                @foreach ($cat['modules'] as $i => $m)
                                    <li @if ($i >= 4) x-show="all" x-cloak @endif><a href="{{ $m['url'] }}" wire:navigate class="pos-module-link">{{ $m['label'] }}</a></li>
                                @endforeach
                            </ul>
                            @if (count($cat['modules']) > 4)
                                <button type="button" class="pos-link" x-on:click="all = ! all" :aria-expanded="all.toString()"><span x-text="all ? 'Show fewer' : 'All {{ count($cat['modules']) }}'"></span></button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-pos.section>
        </div>

        <aside class="pos-ws-side" aria-label="Governance">
            @if ($g['pending'] !== null)
                <x-pos.section title="Awaiting approval" :count="$g['pending']->count()" :link="$g['links']['config'] ?? null" link-label="Change Centre">
                    @if ($g['pending']->isEmpty())
                        <x-pos.state variant="caught-up" size="inline" title="No configuration changes are waiting." />
                    @else
                        <div class="pos-panel pos-stream">
                            @foreach ($g['pending'] as $change)
                                <div class="pos-stream-row" data-tone="{{ in_array($change->risk_level?->value, ['high', 'critical'], true) ? 'danger' : 'warning' }}">
                                    <span class="pos-stream-mark" aria-hidden="true"></span>
                                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $change->subject_label ?? \Illuminate\Support\Str::headline(class_basename((string) $change->subject_type)) }}</span>
                                        <span class="pos-stream-meta">{{ \Illuminate\Support\Str::headline((string) $change->change_type) }} · {{ $change->risk_level?->name ?? 'Risk not set' }} risk{{ $change->effective_from ? ' · from '.$change->effective_from->format('j M') : '' }}</span></span>
                                    <span></span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-pos.section>
            @endif

            @if ($g['dead_letters'] !== null || $g['failed_jobs'] !== null)
                <x-pos.section title="Platform health" :link="$g['links']['readiness'] ?? null" link-label="Readiness">
                    <div class="pos-panel pos-panel-pad pos-figures">
                        @if ($g['dead_letters'] !== null)<x-pos.figure :value="$g['dead_letters']" label="Integration messages to fix" :href="$g['links']['integrations'] ?? null" />@endif
                        @if ($g['failed_jobs'] !== null)<x-pos.figure :value="$g['failed_jobs']" label="Failed background jobs" />@endif
                    </div>
                </x-pos.section>
            @endif

            @if ($g['recent'] !== null && $g['recent']->isNotEmpty())
                <x-pos.section title="Recently changed">
                    <div class="pos-panel pos-stream">
                        @foreach ($g['recent'] as $change)
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
        </aside>
    </div>
</x-filament-panels::page>
