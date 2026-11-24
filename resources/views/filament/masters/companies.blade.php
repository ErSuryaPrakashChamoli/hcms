{{-- Master relationships R4: Companies tab. --}}
<div class="grid gap-3" data-master-tab="companies">
    <p class="text-sm"><span class="font-semibold">Applies to:</span> {{ $summary }}@if ($future) <span class="text-gray-500">(companies created later join)</span>@endif</p>
    @if ($owner)
        <p class="text-sm text-gray-600 dark:text-gray-400">Owned by {{ $owner }}: it always applies there.</p>
    @endif
    @if ($reach === null)
        <x-pos.state variant="caught-up" title="Every company of the organisation uses it." why="It has never been limited to some companies. Use “Applies to” on the General tab to limit or share it." />
    @elseif ($rows === [])
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ implode(', ', $reach) }}</p>
    @else
        <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="Companies">
            <table class="pos-step-table">
                <thead><tr><th>Company</th><th>From</th><th>Until</th><th>How</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr><td class="is-strong">{{ $row['company'] }}</td><td>{{ $row['from'] ?? 'Always' }}</td><td>{{ $row['to'] ?? '—' }}</td><td class="is-quiet">{{ $row['source'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <p class="text-xs text-gray-500">{{ $manageable ? 'Change it with “Applies to…” on the General tab; removing a company that still uses it is refused with the reason.' : 'Changing where it applies needs the configuration sharing permission.' }}</p>
</div>
