{{-- Master relationships R4: Company values tab (configuration overrides). --}}
<div class="grid gap-3" data-master-tab="company-values">
    <p class="text-sm"><span class="font-semibold">Shared values:</span>
        {{ collect($shared)->map(fn ($v, $k) => $k.' '.($v === '' ? '—' : $v))->implode(' · ') }}</p>
    @if ($rows === [])
        <x-pos.state variant="empty" title="No company has its own values." why="Every company uses the shared values. Use “Company values…” on the General tab to give one company its own." />
    @else
        <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="Company values">
            <table class="pos-step-table">
                <thead><tr><th>Company</th><th>Field</th><th>Its own value</th><th>From</th><th>Until</th><th>Now</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr><td class="is-strong">{{ $row['company'] }}</td><td>{{ $row['field'] }}</td><td>{{ $row['value'] }}</td><td>{{ $row['from'] }}</td><td>{{ $row['to'] ?? '—' }}</td><td>{{ $row['current'] ? 'In force' : '' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
