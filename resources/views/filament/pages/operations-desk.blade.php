<x-filament-panels::page>
    {{-- HROne parity WO-1: an operations desk — existing registers in journey order (each entry is checked with its own canAccess()). --}}
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->deskSections() as $section)
            <x-pos.section :title="$section['label']" :sub="$section['description']">
                <div class="pos-panel pos-stream">
                    @foreach ($section['items'] as $item)
                        <a href="{{ $item['url'] }}" wire:navigate class="pos-stream-row">
                            <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-queue-list" class="size-4" /></span>
                            <div class="pos-stream-body">
                                <p class="pos-stream-title">{{ $item['label'] }}</p>
                                @if ($item['description'])<p class="pos-stream-meta">{{ $item['description'] }}</p>@endif
                            </div>
                            <div class="pos-stream-end">
                                @if ($item['count'] !== null)<span class="tabular-nums text-sm" aria-label="{{ $item['count'] }} open">{{ $item['count'] }}</span>@endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </x-pos.section>
        @endforeach
    </div>

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
