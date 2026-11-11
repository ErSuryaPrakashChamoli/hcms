<x-filament-panels::page>
    {{-- HROne parity WO-1: an operations desk — existing registers in journey order (each entry is checked with its own canAccess()):
         the sections down the left, the open one's registers beside them in columns.
         In the step kit's language (theme.css .pos-step-*, .pos-desk-*): what is waiting is said once at the top (one line
         per register with something in it, its count in a tile), the desk's reminders are banners, and the registers below
         carry a count only when something is in them. --}}
    @php
        $sections = $this->deskSections();
        $waiting = collect($sections)->flatMap(fn (array $section) => collect($section['items'])
            ->filter(fn (array $item) => ($item['count'] ?? 0) > 0)
            ->map(fn (array $item) => $item + ['section' => $section['label']]))->values();
        $total = $waiting->sum('count');
    @endphp

    <div class="pos-step pos-desk">
        {{-- HROne parity TX-8a: the desk's reminders (none unless the desk gives some). --}}
        @foreach ($this->notices() as $notice)
            <x-pos.step-banner tone="warning" icon="heroicon-o-bell-alert" :title="$notice['title']" :sub="$notice['body']" data-desk-notice="{{ $notice['key'] }}">
                @if (filled($notice['url'] ?? null))
                    <x-slot:aside>
                        <x-filament::button tag="a" size="sm" :href="$notice['url']" wire:navigate icon="heroicon-m-arrow-right" icon-position="after">{{ $notice['action'] ?? 'Open' }}</x-filament::button>
                    </x-slot:aside>
                @endif
            </x-pos.step-banner>
        @endforeach

        @if ($waiting->isEmpty())
            <x-pos.step-banner tone="clear" title="Nothing waiting" :sub="'No register on the '.\Illuminate\Support\Str::lcfirst((string) $this->getTitle()).' has anything waiting for you right now.'" />
        @else
            <section class="pos-desk-waiting" aria-label="Needs a look">
                <h2 class="pos-step-subhead">Needs a look <span>{{ number_format($total) }}</span></h2>
                <ul class="pos-desk-waiting-list">
                    @foreach ($waiting as $item)
                        <li>
                            <a href="{{ $item['url'] }}" wire:navigate class="pos-desk-waiting-row">
                                <span class="pos-desk-waiting-n" aria-hidden="true">{{ number_format($item['count']) }}</span>
                                <span class="pos-desk-waiting-text">
                                    <strong>{{ $item['label'] }}<span class="sr-only">: {{ $item['count'] }}</span></strong>
                                    <span>{{ $item['section'] }}@if (filled($item['description'])) · {{ $item['description'] }}@endif</span>
                                </span>
                                <x-filament::icon icon="heroicon-m-arrow-right" class="pos-desk-waiting-go" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <x-pos.browse class="pos-desk-browse" :label="(string) $this->getTitle()" :remember="'desk:'.$this::getSlug()"
            :groups="array_map(fn (array $section) => ['key' => \Illuminate\Support\Str::slug($section['label']), 'label' => $section['label'], 'sub' => $section['description'],
                'items' => array_map(fn (array $item) => ['count' => ($item['count'] ?? 0) > 0 ? $item['count'] : null] + $item, $section['items'])], $sections)" />

        @php
            $runs = $this->recentRuns();
        @endphp
        @if ($runs->isNotEmpty())
            <x-pos.section title="Your recent bulk runs" :count="$runs->count()">
                <div class="pos-panel pos-stream">
                    @foreach ($runs as $run)
                        <a href="{{ $this->runUrl($run) }}" wire:navigate class="pos-stream-row">
                            <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-bolt" class="size-4" /></span>
                            <div class="pos-stream-body">
                                <p class="pos-stream-title">{{ $run->operation_label }} · {{ $run->total }} {{ $run->total === 1 ? 'record' : 'records' }}</p>
                                <p class="pos-stream-meta">{{ \App\Domain\Experience\Models\BulkRun::STATUSES[$run->status] ?? $run->status }}@if ($run->isFinished()) · {{ $run->summary() }}@endif · {{ $run->created_at->diffForHumans() }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </x-pos.section>
        @endif
    </div>
</x-filament-panels::page>
