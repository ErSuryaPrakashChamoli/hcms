{{-- HROne parity GO-18 (HROne's wall card app-fcc-fundraiser): the fundraisers open to this person — collected against the
     target amount, "See more" (the fundraiser) and "Donate now" (the pledge form; Fundraisers checks it again). --}}
@if ($h['fundraisers'] ?? null)
    @php($f = $h['fundraisers'])
    <x-pos.section title="Fundraisers" :count="count($f['campaigns'])" :link="$f['url']" link-label="Fundraisers">
        <div class="pos-panel pos-stream">
            @foreach ($f['campaigns'] as $c)
                <div class="pos-stream-row" wire:key="fundraiser-{{ $c['id'] }}">
                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-heart" class="size-4" /></span>
                    <span class="pos-stream-body">
                        <a class="pos-stream-title" href="{{ $c['url'] }}" aria-label="See more about {{ $c['name'] }}">{{ $c['name'] }}</a>
                        <span class="pos-stream-meta">Collected {{ number_format($c['collected'], 2) }} · Target amount {{ number_format($c['target'], 2) }} {{ $c['currency'] }} · until {{ $c['ends_on'] }}</span>
                    </span>
                    <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="mountAction('donate', @js(['campaign' => $c['id']]))" data-pos-action="donate">Donate now</button>
                </div>
            @endforeach
        </div>
    </x-pos.section>
@endif
