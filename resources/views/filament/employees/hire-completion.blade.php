@php
    /** @var array<string, mixed> $summary  @var list<array{label: string, description: string, url: string}> $links */
@endphp
{{-- HROne parity WO-3 (§23.2.3 #22): what the hire created, what was left for later, and the next steps. --}}
<div class="grid gap-4 md:grid-cols-2">
    <x-pos.section :title="$summary['name'].' · '.$summary['code']" :sub="'Hired'.($summary['state'] ? ' · '.$summary['state'] : '')">
        <div class="pos-panel pos-stream">
            @foreach ($summary['created'] as $item)
                <div class="pos-stream-row">
                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-check-circle" class="size-4" /></span>
                    <div class="pos-stream-body"><p class="pos-stream-title">{{ $item }}</p><p class="pos-stream-meta">Created</p></div>
                </div>
            @endforeach
            @if (filled($summary['invitation']))
                <div class="pos-stream-row">
                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-envelope" class="size-4" /></span>
                    <div class="pos-stream-body"><p class="pos-stream-title">{{ $summary['invitation'] }}</p><p class="pos-stream-meta">Sign-in</p></div>
                </div>
            @endif
            @if (filled($summary['invitation_refused']))
                <div class="pos-stream-row">
                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-exclamation-triangle" class="size-4" /></span>
                    <div class="pos-stream-body"><p class="pos-stream-title">Sign-in not created: {{ $summary['invitation_refused'] }}</p><p class="pos-stream-meta">The hire is saved; invite them later from People → Employees ("Invite to sign in").</p></div>
                </div>
            @endif
        </div>
    </x-pos.section>

    <x-pos.section title="Next steps" sub="Consider these to complete the hire">
        <div class="pos-panel pos-stream">
            @foreach ($links as $link)
                <a href="{{ $link['url'] }}" wire:navigate class="pos-stream-row">
                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-arrow-right-circle" class="size-4" /></span>
                    <div class="pos-stream-body"><p class="pos-stream-title">{{ $link['label'] }}</p><p class="pos-stream-meta">{{ $link['description'] }}</p></div>
                </a>
            @endforeach
        </div>
    </x-pos.section>

    @if (! empty($summary['skipped']))
        <x-pos.section title="Left for later" :sub="'Skipped in the wizard: add them on the Employee 360 when ready'">
            <div class="pos-panel pos-stream">
                @foreach ($summary['skipped'] as $item)
                    <div class="pos-stream-row">
                        <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-minus-circle" class="size-4" /></span>
                        <div class="pos-stream-body"><p class="pos-stream-title">{{ $item }}</p><p class="pos-stream-meta">Skipped</p></div>
                    </div>
                @endforeach
            </div>
        </x-pos.section>
    @endif
</div>
