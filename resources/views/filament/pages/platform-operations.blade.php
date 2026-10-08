<x-filament-panels::page>
    @php($platform = $this->platform())
    @php($rows = $this->tenantRows())
    @php($selected = $this->selected())
    <x-filament::section heading="Platform">
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <table class="w-full text-sm">
                    <caption class="sr-only">Runtime health</caption>
                    <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1">Check</th><th scope="col">Status</th><th scope="col">Detail</th></tr></thead>
                    <tbody>
                        @foreach ($platform['health'] as $name => $check)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="py-1">{{ str_replace('_', ' ', $name) }}</td>
                                <td><x-filament::badge size="sm" :color="match ($check['status']) { 'ok' => 'success', 'warn' => 'warning', default => 'danger' }">{{ strtoupper($check['status']) }}</x-filament::badge></td>
                                <td class="text-gray-600 dark:text-gray-300">{{ is_array($check['detail'] ?? null) ? collect($check['detail'])->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.(is_scalar($v) || $v === null ? ($v ?? '—') : json_encode($v)))->implode(', ') : ($check['detail'] ?? '') }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1">platform audit chain</td>
                            @php($run = $platform['platform_audit'])
                            <td><x-filament::badge size="sm" :color="$run === null ? 'warning' : ($run->valid ? 'success' : 'danger')">{{ $run === null ? 'NOT VERIFIED' : ($run->valid ? 'INTACT' : 'BROKEN') }}</x-filament::badge></td>
                            <td class="text-gray-600 dark:text-gray-300">{{ $run === null ? 'Run peopleos:audit:verify (scheduled daily)' : $run->checked.' events, '.$run->finished_at->diffForHumans() }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div>
                <h3 class="mb-1 text-sm font-medium">Failed jobs by job (newest 1,000)</h3>
                @if ($platform['failed_jobs'] === [])
                    <p class="text-sm text-gray-600 dark:text-gray-300">No failed jobs.</p>
                @else
                    <table class="w-full text-sm">
                        <caption class="sr-only">Failed jobs by job class</caption>
                        <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1">Job</th><th scope="col">Failed</th><th scope="col">Last failure</th></tr></thead>
                        <tbody>
                            @foreach ($platform['failed_jobs'] as $job)
                                <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 font-mono">{{ $job['job'] }}</td><td>{{ $job['count'] }}</td><td>{{ $job['last'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="mt-1 text-xs text-gray-500">Inspect and retry with <code>php artisan queue:failed</code> / <code>queue:retry</code>; pruned after the configured window.</p>
                @endif
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Tenants" description="Tenants that need attention first. Counts only: an operator enters a tenant (audited) to see its records.">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <caption class="sr-only">Tenant operations</caption>
                <thead>
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="py-1 pe-4">Tenant</th><th scope="col" class="pe-4">Status</th><th scope="col" class="pe-4">Deferred jobs</th>
                        <th scope="col" class="pe-4">Notifications failed (7 d) / stuck</th><th scope="col" class="pe-4">Failing webhooks</th><th scope="col" class="pe-4">Dead letters</th>
                        <th scope="col" class="pe-4">First admin</th><th scope="col">Audit chain</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr @class(['border-t border-gray-100 dark:border-gray-800', 'bg-primary-50 dark:bg-white/5' => $selected && $selected['tenant']->id === $row['tenant']->id])>
                            <td class="py-1 pe-4"><button type="button" class="text-primary-600 underline dark:text-primary-400" wire:click="$set('tenant', {{ $row['tenant']->id }})">{{ $row['tenant']->name }}</button></td>
                            <td class="pe-4">
                                <x-filament::badge size="sm" :color="$row['tenant']->status->getColor()">{{ $row['tenant']->status->getLabel() }}</x-filament::badge>
                                @if ($row['tenant']->suspended_at)<span class="text-xs text-gray-500">since {{ $row['tenant']->suspended_at->toDateString() }}{{ $row['tenant']->suspended_from ? ' (was '.$row['tenant']->suspended_from.')' : '' }}</span>@endif
                            </td>
                            <td class="pe-4">{{ $row['deferred'] ?: '—' }}</td>
                            <td class="pe-4">{{ $row['failed_notifications'] }} / <span @class(['font-semibold text-danger-600 dark:text-danger-400' => $row['stuck_notifications'] > 0])>{{ $row['stuck_notifications'] }}</span></td>
                            <td class="pe-4">{{ $row['failing_webhooks'] ?: '—' }}</td>
                            <td class="pe-4">{{ $row['dead_letters'] ?: '—' }}</td>
                            <td class="pe-4">@if ($row['invitation_needed'])<x-filament::badge size="sm" color="warning">Invitation not sent</x-filament::badge>@else — @endif</td>
                            <td>
                                @php($run = $row['audit'])
                                <x-filament::badge size="sm" :color="$run === null ? 'gray' : ($run->valid ? 'success' : 'danger')">{{ $run === null ? 'Not verified' : ($run->valid ? 'Intact' : 'Broken') }}</x-filament::badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @if ($selected)
        <x-filament::section :heading="$selected['tenant']->name">
            <div class="space-y-1 text-sm">
                <div><strong>Status:</strong> {{ $selected['tenant']->status->getLabel() }} · <a class="text-primary-600 underline dark:text-primary-400" href="{{ $this->tenantUrl($selected['tenant']) }}">Tenant record</a></div>
                <div><strong>Deferred jobs:</strong> {{ $selected['deferred'] }} @if ($selected['deferred'] > 0 && ! $selected['tenant']->isAccessible()) (dispatched again when the tenant is reactivated) @endif</div>
                <div><strong>Stuck notifications</strong> are marked failed by the recovery sweep; an administrator of the tenant can send a failed one again from its delivery log.</div>
                @if ($selected['invitation_needed'])<div><strong>First administrator:</strong> no invitation they can accept: use <em>Send invitation</em> on the Tenants list.</div>@endif
                @if ($selected['audit'] && ! $selected['audit']->valid)<div class="text-danger-600 dark:text-danger-400"><strong>Audit chain broken</strong> at event {{ $selected['audit']->broken_event_id }}: {{ $selected['audit']->reason }}. Follow the incident runbook.</div>@endif
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
