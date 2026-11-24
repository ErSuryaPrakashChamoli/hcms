{{-- Master relationships R4: Links tab. Optional links show "Not set"; nothing here is required to create the master. --}}
<div class="grid gap-4" data-master-tab="links">
    @if ($links !== [])
        <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="Links">
            <table class="pos-step-table">
                <thead><tr><th>Link</th><th>Shared value</th><th>Companies with their own value</th><th>What needs it</th></tr></thead>
                <tbody>
                    @foreach ($links as $link)
                        <tr data-link="{{ $link['label'] }}">
                            <td class="is-strong">{{ $link['label'] }}</td>
                            <td>@if ($link['value'] !== null){{ $link['value'] }}@else<span class="text-gray-500">Not set (optional)</span>@endif</td>
                            <td class="is-quiet">{{ $link['own'] === [] ? '—' : implode('; ', $link['own']) }}</td>
                            <td class="is-quiet">{{ $link['needed'] === [] ? 'Optional' : implode(' ', $link['needed']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500">Set or clear links on the General tab. A business process that needs one (hiring with the employment data requirements, payroll readiness) says so when it runs.</p>
    @endif
    @if ($placement !== null)
        <section class="grid gap-1" data-placement>
            <h3 class="text-sm font-semibold">Place in the organisation structure</h3>
            @if ($placement['placed'])
                <p class="text-sm">{{ implode(' › ', $placement['path']) }}</p>
            @else
                <p class="text-sm text-warning-600">Not placed yet. That is fine to create it; place it when the structure is ready (it is listed in Organisation → Unmapped masters).</p>
            @endif
            @if ($placement['url'])
                <a class="pos-link text-sm" href="{{ $placement['url'] }}">{{ $placement['placed'] ? 'Move it in the Organisation Designer' : 'Place it in the Organisation Designer' }}</a>
            @endif
        </section>
    @endif
    @if ($establishment !== null)
        <section class="grid gap-1" data-establishment>
            <h3 class="text-sm font-semibold">Establishment</h3>
            @if ($establishment['name'])
                <p class="text-sm">{{ $establishment['name'] }}</p>
            @elseif ($establishment['several'])
                <p class="text-sm text-warning-600">None. {{ $establishment['company'] }} has more than one establishment, so people here take the head office's for PF, ESI, PT and LWF. Set the establishment on the General tab.</p>
            @else
                <p class="text-sm text-gray-500">None (the company's only establishment applies).</p>
            @endif
        </section>
    @endif
</div>
