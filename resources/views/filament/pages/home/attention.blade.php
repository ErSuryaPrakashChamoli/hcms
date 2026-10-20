{{-- Need attention: ranked, each with its reason and one verb --}}
@if ($attention->isNotEmpty())
    <x-pos.section title="Need attention" :count="$attention->count()" :link="\App\Filament\Pages\MyWork::getUrl()" link-label="My work">
        <x-pos.phone-cap :total="$attention->count()">
        <div class="pos-panel pos-stream">
            @foreach ($attention as $n)
                <div class="pos-stream-row pos-row-actions-below" data-tone="{{ $n['severity'] }}" wire:key="next-{{ md5($n['key']) }}" wire:transition>
                    <span class="pos-stream-mark" aria-hidden="true"></span>
                    <div class="pos-stream-body">
                        <p class="pos-stream-title">{{ $n['title'] }}</p>
                        <p class="pos-stream-meta">{{ $n['domain'] }}@if ($n['detail']) · {{ $n['detail'] }}@endif
                            @if ($n['due']) · <span class="{{ $n['due']->isPast() ? 'text-pos-danger' : '' }}">{{ $n['due']->isPast() ? 'overdue '.$n['due']->diffForHumans(null, true) : 'due '.$n['due']->diffForHumans() }}</span>@endif</p>
                    </div>
                    <div class="pos-stream-end">
                        <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="notNow(@js($n['key']))">Not now</button>
                        <a href="{{ $n['url'] }}" wire:navigate class="pos-btn pos-btn-secondary pos-btn-sm">{{ $n['verb'] }}</a>
                    </div>
                </div>
            @endforeach
        </div>
        </x-pos.phone-cap>
    </x-pos.section>
@endif
