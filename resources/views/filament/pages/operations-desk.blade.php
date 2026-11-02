<x-filament-panels::page>
    {{-- HROne parity WO-1: an operations desk — existing registers in journey order (each entry is checked with its own canAccess()):
         the sections down the left, the open one's registers beside them in columns. --}}
    {{-- HROne parity TX-8a: the desk's reminders (none unless the desk gives some). --}}
    @foreach ($this->notices() as $notice)
        <x-filament::section :heading="$notice['title']" icon="heroicon-o-bell-alert" icon-color="warning" compact data-desk-notice="{{ $notice['key'] }}">
            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <p>{{ $notice['body'] }}</p>
                @if (filled($notice['url'] ?? null))
                    <x-filament::button tag="a" size="sm" :href="$notice['url']" wire:navigate>{{ $notice['action'] ?? 'Open' }}</x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @endforeach

    <x-pos.browse :label="(string) $this->getTitle()" :remember="'desk:'.$this::getSlug()"
        :groups="array_map(fn (array $section) => ['key' => \Illuminate\Support\Str::slug($section['label']), 'label' => $section['label'], 'sub' => $section['description'], 'items' => $section['items']], $this->deskSections())" />

    @php($runs = $this->recentRuns())
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
</x-filament-panels::page>
