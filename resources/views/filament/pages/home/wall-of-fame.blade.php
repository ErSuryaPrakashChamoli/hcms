{{-- HROne setup parity HP-9c (EN-03): the wall of fame — who received the most public badges this month. --}}
@if ($h['wall_of_fame'] ?? null)
    @php($w = $h['wall_of_fame'])
    <x-pos.section title="Wall of fame" sub="Most badges this month." :link="$w['url']" link-label="Recognition">
        <div class="pos-panel pos-stream">
            @foreach ($w['people'] as $row)
                <div class="pos-stream-row" wire:key="fame-{{ $row['employee']->id }}">
                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$row['badge']?->icon ?? 'heroicon-o-trophy'" class="size-4" /></span>
                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $row['employee']->person?->display_name }}</span><span class="pos-stream-meta">{{ $row['badge']?->name }}</span></span>
                    <x-pos.status tone="success" :label="$row['count'].' '.($row['count'] === 1 ? 'badge' : 'badges')" />
                </div>
            @endforeach
        </div>
    </x-pos.section>
@endif
