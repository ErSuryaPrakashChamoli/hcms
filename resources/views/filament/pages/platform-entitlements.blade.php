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
                · plan: <strong>{{ $first['plan'] ? $first['plan']['code'].' v'.$first['plan']['version'].' ('.$first['plan']['name'].'), assignment #'.$first['assignment']['id'].' from '.$first['assignment']['from'].($first['assignment']['to'] ? ' to '.$first['assignment']['to'] : '') : 'none in force' }}</strong>
                · version {{ $first['version'] }}</p>
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Decisions">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1">Capability</th><th scope="col">Type</th><th scope="col">Enforcement class</th><th scope="col">Decision</th><th scope="col">Reason</th><th scope="col">Source</th><th scope="col">In the plan</th><th scope="col">Value / limit</th></tr></thead>
                <tbody>
                    @foreach ($catalog as $row)
                        @php($d = $row['decision'])
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1"><code>{{ $d->capability->value }}</code> <span class="text-gray-500">{{ $d->capability->label() }}</span></td>
                            <td>{{ $d->capability->type()->value }}</td>
                            <td>{{ str_replace('_', ' ', $d->capability->enforcement()->value) }}</td>
                            <td><x-filament::badge size="sm" :color="match ($d->outcome->value) { 'ALLOW' => 'success', 'DENY' => 'danger', 'UNKNOWN' => 'warning', default => 'gray' }">{{ $d->outcome->value }}</x-filament::badge></td>
                            <td>{{ $d->reason->value }}</td>
                            <td>{{ $d->source->value }}{{ $d->overrideId ? ' #'.$d->overrideId : ($d->entitlementId ? ' #'.$d->entitlementId : ($d->source->value === 'plan' && $row['plan'] ? ' '.$row['plan']['code'].' v'.$row['plan']['version'] : '')) }}</td>
                            <td>{{ $row['assignment'] && $d->capability->commercial() ? ($row['plan_entitlement'] === null ? 'not in plan' : ($d->capability->type()->value === 'limit' ? ($row['plan_entitlement']['value_int'] ?? 'unlimited') : ($row['plan_entitlement']['value_bool'] ? 'included' : 'excluded'))) : '' }}</td>
                            <td>{{ $d->capability->type()->value === 'limit' ? ($d->limit ?? ($d->reason->value === 'UNLIMITED' ? 'unlimited' : '—')) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-filament::section>

        @php($history = $this->history())
        <x-filament::section heading="Plan assignments" collapsible>
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Plan assignments">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1">#</th><th scope="col">Plan version</th><th scope="col">From</th><th scope="col">Last day</th><th scope="col">Status</th><th scope="col">Reason</th><th scope="col">Reference</th></tr></thead>
                <tbody>
                    @forelse ($history['assignments'] as $a)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1">{{ $a->id }}</td>
                            <td><code>{{ $a->planVersion->label() }}</code> <span class="text-gray-500">{{ $a->planVersion->plan->name }}</span></td>
                            <td>{{ $a->effective_from->toDateString() }}</td>
                            <td>{{ $a->effective_to?->toDateString() ?? 'open' }}</td>
                            <td>{{ $a->status }}{{ $a->closed_at ? ' (closed '.$a->closed_at->toDateString().')' : '' }}</td>
                            <td>{{ $a->reason }}{{ $a->close_reason ? ' · closed: '.$a->close_reason : '' }}</td>
                            <td>{{ $a->reference }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-2 text-gray-500">Never on a plan.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-filament::section>

        @foreach (['configuration' => 'Configuration rows', 'overrides' => 'Overrides'] as $key => $heading)
            <x-filament::section :heading="$heading" collapsible>
                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ $heading }}">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1">#</th><th scope="col">Capability</th><th scope="col">Value</th><th scope="col">From</th><th scope="col">Last day</th><th scope="col">Status</th><th scope="col">Reason</th><th scope="col">Reference</th></tr></thead>
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

        <x-filament::section heading="Commercial audit trail (Markedge platform chain)" collapsible collapsed>
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Commercial audit trail">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1">When (UTC)</th><th scope="col">Action</th><th scope="col">By</th><th scope="col">Effective</th><th scope="col">Changes</th><th scope="col">Reason</th></tr></thead>
                <tbody>
                    @forelse ($this->auditTrail() as $event)
                        <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                            <td class="py-1 whitespace-nowrap">{{ $event->occurred_at?->toDateTimeString() }}</td>
                            <td>{{ $event->action->value }}</td>
                            <td>{{ $event->actor_name ?? '—' }}</td>
                            <td>{{ $event->effective_date?->toDateString() ?? '—' }}</td>
                            <td>
                                @foreach ($event->fieldChanges as $change)
                                    <div><code>{{ $change->field }}</code>: {{ $change->before ?? '—' }} → {{ $change->after ?? '—' }}</div>
                                @endforeach
                            </td>
                            <td>{{ $event->reason }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-2 text-gray-500">No commercial changes recorded for this tenant.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Shadow observations, last 7 days{{ $tenant ? '' : ' (all tenants)' }}">
        <p class="text-xs text-gray-500 mb-2">What would have been denied or could not be decided. Occurrences are a lower bound (sampled per 10-minute window).</p>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Shadow observations">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1">Capability</th><th scope="col">Decision</th><th scope="col">Reason</th><th scope="col">Surface</th><th scope="col">Occurrences</th><th scope="col">Tenants</th><th scope="col">Last seen</th></tr></thead>
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
