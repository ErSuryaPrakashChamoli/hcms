<x-filament-panels::page>
    @php($tenant = $this->selectedTenant())
    <x-filament::section>
        <div class="flex flex-wrap items-end gap-4 text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-gray-500">Tenant</span>
                <select wire:model.live="tenant" class="fi-input rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="">All tenants (shadow summary only)</option>
                    @foreach ($this->tenantOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-gray-500">Business date (UTC)</span>
                <input type="date" wire:model.live="day" class="fi-input rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900" />
            </label>
            <p class="text-gray-600 dark:text-gray-300 max-w-xl">Shadow mode: decisions are evaluated and observed, never enforced. Nothing on this page changes what a tenant can use today.</p>
        </div>
    </x-filament::section>

    @if ($tenant)
        @php($catalog = $this->catalog())
        @php($first = $catalog->first())
        <x-filament::section heading="Decisions for {{ $tenant->name }} on {{ $this->day }}">
            <p class="text-sm mb-2">Commercial configuration:
                <strong>{{ $first['configured_from'] ? 'configured from '.$first['configured_from'] : 'unconfigured (commercial capabilities are UNKNOWN)' }}</strong>
                · version {{ $first['version'] }}</p>
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th class="py-1">Capability</th><th>Type</th><th>Enforcement class</th><th>Decision</th><th>Reason</th><th>Source</th><th>Value / limit</th></tr></thead>
                <tbody>
                    @foreach ($catalog as $row)
                        @php($d = $row['decision'])
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1"><code>{{ $d->capability->value }}</code> <span class="text-gray-500">{{ $d->capability->label() }}</span></td>
                            <td>{{ $d->capability->type()->value }}</td>
                            <td>{{ str_replace('_', ' ', $d->capability->enforcement()->value) }}</td>
                            <td><x-filament::badge size="sm" :color="match ($d->outcome->value) { 'ALLOW' => 'success', 'DENY' => 'danger', 'UNKNOWN' => 'warning', default => 'gray' }">{{ $d->outcome->value }}</x-filament::badge></td>
                            <td>{{ $d->reason->value }}</td>
                            <td>{{ $d->source->value }}{{ $d->overrideId ? ' #'.$d->overrideId : ($d->entitlementId ? ' #'.$d->entitlementId : '') }}</td>
                            <td>{{ $d->capability->type()->value === 'limit' ? ($d->limit ?? ($d->reason->value === 'UNLIMITED' ? 'unlimited' : '—')) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-filament::section>

        @php($history = $this->history())
        @foreach (['configuration' => 'Configuration rows', 'overrides' => 'Overrides'] as $key => $heading)
            <x-filament::section :heading="$heading" collapsible>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">#</th><th>Capability</th><th>Value</th><th>From</th><th>Last day</th><th>Status</th><th>Reason</th><th>Reference</th></tr></thead>
                    <tbody>
                        @forelse ($history[$key] as $r)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="py-1">{{ $r->id }}</td>
                                <td><code>{{ $r->capability->value }}</code></td>
                                <td>{{ $r->capability->type()->value === 'limit' ? ($r->value_int ?? 'unlimited') : ($r->value_bool ? 'available' : 'not available') }}</td>
                                <td>{{ $r->effective_from->toDateString() }}</td>
                                <td>{{ $r->effective_to?->toDateString() ?? 'open' }}</td>
                                <td>{{ $r->status }}{{ $r->closed_at ? ' (closed '.$r->closed_at->toDateString().')' : '' }}</td>
                                <td>{{ $r->reason }}</td>
                                <td>{{ $r->reference }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-2 text-gray-500">None.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </x-filament::section>
        @endforeach
    @endif

    <x-filament::section heading="Shadow observations, last 7 days{{ $tenant ? '' : ' (all tenants)' }}">
        <p class="text-xs text-gray-500 mb-2">What would have been denied or could not be decided. Occurrences are a lower bound (sampled per 10-minute window).</p>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Capability</th><th>Decision</th><th>Reason</th><th>Surface</th><th>Occurrences</th><th>Tenants</th><th>Last seen</th></tr></thead>
            <tbody>
                @forelse ($this->shadow() as $s)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1"><code>{{ $s->capability }}</code></td>
                        <td>{{ $s->outcome }}</td>
                        <td>{{ $s->reason }}</td>
                        <td><code>{{ $s->surface }}</code></td>
                        <td>{{ $s->occurrences }}</td>
                        <td>{{ $s->tenants }}</td>
                        <td>{{ $s->last_seen_at }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-2 text-gray-500">No observations yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
