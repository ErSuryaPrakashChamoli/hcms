{{-- UX.16: your own requests and where each one stands (leave, attendance corrections, HR requests, letters). --}}
@if (($h['requests'] ?? []) !== [])
    <x-pos.section title="My requests" :count="count($h['requests'])" :link="\App\Filament\Pages\MyWork::getUrl(['tab' => 'waiting'])" link-label="All requests">
        <div class="pos-panel pos-stream">
            @foreach ($h['requests'] as $r)
                <div class="pos-stream-row" data-tone="info" wire:key="req-{{ md5($r['key']) }}">
                    <span class="pos-stream-mark" aria-hidden="true"></span>
                    <div class="pos-stream-body">
                        <p class="pos-stream-title">{{ $r['title'] }}</p>
                        <p class="pos-stream-meta">{{ $r['domain'] }}@if ($r['detail']) · {{ $r['detail'] }}@endif</p>
                    </div>
                    <div class="pos-stream-end">
                        @if ($r['url'])<a href="{{ $r['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Open<span class="sr-only">: {{ $r['title'] }}</span></a>@endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-pos.section>
@endif
