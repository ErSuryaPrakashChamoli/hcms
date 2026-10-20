{{-- Team pulse (managers): who is in, who needs you --}}
@if ($h['pulse_team'])
    @php($tp = $h['pulse_team'])
    <x-pos.section title="Team pulse" :link="$tp['url']" link-label="Your team">
        <div class="pos-panel pos-panel-pad grid gap-4">
            <div class="pos-figures">
                @foreach (['team_in', 'team_away', 'approvals', 'attention'] as $k)
                    @if ($f = $kpi($k))<x-pos.figure :value="$f['value']" :label="$f['label']" :href="$f['url'] ?? null" />@endif
                @endforeach
                @if (! $kpi('team_in'))<x-pos.figure :value="$tp['size']" label="Direct reports" />@endif
            </div>
            <ul class="pos-people-strip" aria-label="Your team">
                @foreach ($tp['people'] as $p)
                    <li><x-pos.person :id="$p['id']" :name="$p['name']" :sub="$p['away'] ? 'away' : null" /></li>
                @endforeach
                @if ($tp['more'] > 0)<li class="pos-meta self-center">+{{ $tp['more'] }} more</li>@endif
            </ul>
            @if ($tp['reviews'] > 0)<p class="pos-meta">{{ $tp['reviews'] }} {{ $tp['reviews'] === 1 ? 'review' : 'reviews' }} to write.</p>@endif
        </div>
    </x-pos.section>
@endif
