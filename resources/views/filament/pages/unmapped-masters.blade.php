<x-filament-panels::page>
    @php
        $designations = $this->designations();
        $unplaced = $this->unplaced();
        $locations = $this->locationsWithoutEstablishment();
    @endphp

    <p class="text-sm text-gray-600 dark:text-gray-400" style="max-width: 60rem">
        Masters are created on their own and linked later. These links are not set where a business process would use
        them. None stops anything by itself unless marked as blocking; each row says what follows and where to fix it.
    </p>

    <section class="grid gap-2" data-unmapped="designations">
        <h2 class="text-base font-semibold">Designations without a grade ({{ count($designations) }})</h2>
        @if ($designations === [])
            <x-pos.state variant="caught-up" title="Every designation has a grade." />
        @else
            <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="Designations without a grade">
                <table class="pos-step-table">
                    <thead><tr><th>Designation</th><th>Companies with their own grade</th><th>What follows</th><th><span class="sr-only">Fix</span></th></tr></thead>
                    <tbody>
                        @foreach ($designations as $row)
                            <tr data-code="{{ $row['code'] }}">
                                <td class="is-strong">{{ $row['name'] }} <span class="text-gray-500">({{ $row['code'] }})</span></td>
                                <td class="is-quiet">{{ $row['own'] === [] ? '—' : implode(', ', $row['own']) }}</td>
                                <td>@if ($row['blocker'])<x-filament::badge color="danger">Blocking</x-filament::badge> @endif{{ $row['effect'] }}</td>
                                <td>@if ($row['url'])<a class="pos-link" href="{{ $row['url'] }}">Set its grade</a>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="grid gap-2" data-unmapped="unplaced">
        <h2 class="text-base font-semibold">Units not placed in the organisation structure ({{ count($unplaced) }})</h2>
        @if ($unplaced === [])
            <x-pos.state variant="caught-up" title="Every unit is placed." />
        @else
            <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="Units not placed">
                <table class="pos-step-table">
                    <thead><tr><th>Unit</th><th>Kind</th><th>What follows</th><th><span class="sr-only">Fix</span></th></tr></thead>
                    <tbody>
                        @foreach ($unplaced as $row)
                            <tr data-code="{{ $row['code'] }}">
                                <td class="is-strong">{{ $row['name'] }} <span class="text-gray-500">({{ $row['code'] }})</span></td>
                                <td>{{ $row['kind'] }}</td>
                                <td class="is-quiet">Seats and the structure chart leave it out until it is placed.</td>
                                <td>@if ($row['place'])<a class="pos-link" href="{{ $row['place'] }}">Place it</a>@elseif ($row['url'])<a class="pos-link" href="{{ $row['url'] }}">Open</a>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="grid gap-2" data-unmapped="establishments">
        <h2 class="text-base font-semibold">Locations without an establishment, in companies with more than one ({{ count($locations) }})</h2>
        @if ($locations === [])
            <x-pos.state variant="caught-up" title="Every such location has its establishment." />
        @else
            <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="Locations without an establishment">
                <table class="pos-step-table">
                    <thead><tr><th>Location</th><th>Company</th><th>What follows</th><th><span class="sr-only">Fix</span></th></tr></thead>
                    <tbody>
                        @foreach ($locations as $row)
                            <tr data-code="{{ $row['code'] }}">
                                <td class="is-strong">{{ $row['name'] }} <span class="text-gray-500">({{ $row['code'] }})</span></td>
                                <td>{{ $row['company'] }}</td>
                                <td class="is-quiet">People here take the head office's establishment for PF, ESI, PT and LWF (payroll readiness lists them).</td>
                                <td>@if ($row['url'])<a class="pos-link" href="{{ $row['url'] }}">Set its establishment</a>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-filament-panels::page>
