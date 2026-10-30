{{-- HROne parity RQ-2: "Who approves this" (a read-only preview: nothing is started or sent until the form is submitted). --}}
@php
    /** @var array $preview */
    $modes = ['single' => null, 'sequential' => 'one after another', 'parallel' => 'all of them', 'any' => 'any one of them'];
@endphp
<div class="space-y-3" data-request-route>
    @foreach ($preview['routes'] as $route)
        <div>
            <p class="pos-body-sm font-semibold">
                {{ $route['conditional'] ? 'May go to the workflow' : 'Approval workflow' }} “{{ $route['workflow'] }}”
                @if ($route['conditional'])<span class="pos-caption pos-muted font-normal">(it depends on the details of your request)</span>@endif
            </p>
            @if ($route['steps'] === [])
                <p class="pos-caption pos-muted">No approval step: the workflow decides it on its own.</p>
            @else
                <ol class="mt-1 space-y-1">
                    @foreach ($route['steps'] as $i => $step)
                        <li class="pos-body-sm">
                            <span class="pos-muted">Level {{ $i + 1 }}:</span> {{ implode(', ', $step['approvers']) }}
                            @if ($modes[$step['mode']] ?? null)<span class="pos-caption pos-muted">({{ $modes[$step['mode']] }})</span>@endif
                            @if ($step['conditional'])<span class="pos-caption pos-muted">· only in some cases</span>@endif
                            @if ($step['hours'])<span class="pos-caption pos-muted">· usually within {{ rtrim(rtrim(number_format($step['hours'], 1), '0'), '.') }} hours</span>@endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    @endforeach
    @if ($preview['built_in'])
        <div>
            <p class="pos-body-sm font-semibold">{{ $preview['routes'] === [] ? 'Approved by' : 'Otherwise approved by' }}</p>
            <p class="pos-body-sm">{{ $preview['built_in'] }}</p>
            @if ($preview['manager'])<p class="pos-caption pos-muted">Your manager, {{ $preview['manager'] }}, approves these requests.</p>@endif
        </div>
    @endif
    @if ($preview['informed'] !== [])
        <div>
            <p class="pos-body-sm font-semibold">Informed</p>
            <p class="pos-body-sm">{{ implode(', ', $preview['informed']) }}</p>
        </div>
    @endif
    @if ($preview['policies'] !== [])
        <div>
            <p class="pos-body-sm font-semibold">Read the policy</p>
            <ul class="mt-1 space-y-1">
                @foreach ($preview['policies'] as $policy)
                    <li class="pos-body-sm">
                        @if ($policy['url'])<a href="{{ $policy['url'] }}" target="_blank" rel="noopener" class="pos-link">{{ $policy['title'] }}</a>@else{{ $policy['title'] }}@endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
