{{--
    UX.16: personal items for people whose Home leads with another role (manager, HR, payroll, executive, administrator).
    The same items as the employee view, compact: they never disappear, but they do not lead.
--}}
@php($mine = collect($h['personal'] ?? [])->map(fn ($s) => ['key' => $s['key'], 'title' => $s['title'], 'url' => $s['url'], 'severity' => $s['severity']])
    ->merge($attention->map(fn ($n) => ['key' => $n['key'], 'title' => $n['title'], 'url' => $n['url'], 'severity' => $n['severity']]))->values())
@if ($mine->isNotEmpty())
    <x-pos.section title="For you" :count="$mine->count()" :link="\App\Filament\Pages\MyWork::getUrl()" link-label="My work">
        <div class="pos-panel pos-stream">
            @foreach ($mine->take(3) as $m)
                <div class="pos-stream-row pos-stream-row-compact" data-tone="{{ $m['severity'] }}" wire:key="mine-{{ md5($m['key']) }}">
                    <span class="pos-stream-mark" aria-hidden="true"></span>
                    <div class="pos-stream-body"><p class="pos-stream-title">{{ $m['title'] }}</p></div>
                    <div class="pos-stream-end">@if ($m['url'])<a href="{{ $m['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Open<span class="sr-only">: {{ $m['title'] }}</span></a>@endif</div>
                </div>
            @endforeach
            @if ($mine->count() > 3)
                <div class="pos-stream-more"><a href="{{ \App\Filament\Pages\MyWork::getUrl() }}" wire:navigate class="pos-link">{{ $mine->count() - 3 }} more in My work</a></div>
            @endif
        </div>
    </x-pos.section>
@endif
