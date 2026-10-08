<x-filament-panels::page>
    @php($tenantModel = $this->selectedTenant())
    <x-filament::section>
        <div class="flex flex-wrap items-end gap-4 text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-gray-500 dark:text-gray-400">Tenant</span>
                <select wire:model.live="tenant" class="fi-input rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="">All tenants</option>
                    @foreach (\App\Domain\Platform\Models\Tenant::query()->orderBy('name')->pluck('name', 'id') as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <p class="text-gray-600 dark:text-gray-300 max-w-3xl">Commercial state is separate from a tenant's technical status, and nothing here is enforced: a lapsed subscription only means no plan is in force (UNKNOWN for entitlements). Dates are business dates in UTC. No price, payment or invoice exists in PeopleOS yet.</p>
        </div>
    </x-filament::section>

    @if ($tenantModel)
        @php($state = $this->stateToday())
        @php($live = $this->live())
        <x-filament::section heading="{{ $tenantModel->name }} today ({{ $this->today() }})">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-gray-500 dark:text-gray-400">Technical status</dt><dd class="font-medium">{{ $tenantModel->status->getLabel() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Commercial state</dt><dd>
                    @if ($state)
                        <x-filament::badge size="sm" :color="$state['status']->color()">{{ $state['status']->value }}</x-filament::badge>
                        @if ($state['derived'])<span class="text-gray-500 dark:text-gray-400"> (its end has passed; the daily settlement records it)</span>@endif
                    @else
                        <span class="text-gray-500 dark:text-gray-400">no subscription in force{{ $live ? ' yet (scheduled)' : '' }}</span>
                    @endif
                </dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Plan version</dt><dd class="font-medium">{{ $state ? $this->versionLabel($state['plan_version_id']) : '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Current period</dt><dd class="font-medium">{{ $state ? $state['from'].' to '.($state['to'] ?? 'open') : '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Subscription</dt><dd class="font-medium">{{ $live ? '#'.$live->id : 'none live' }}</dd></div>
                <div class="sm:col-span-2 lg:col-span-3"><dt class="text-gray-500 dark:text-gray-400">Legacy tenant fields (metadata, never read as commercial state)</dt>
                    <dd>status "{{ $tenantModel->status->value }}" · trial end {{ $tenantModel->trial_ends_at?->toDateString() ?? 'not set' }} · tier {{ $tenantModel->tier ?? '—' }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Subscription timeline" description="Each row is one span with one commercial state and the plan version in force. History is never rewritten: a change ends the row in force the day before; a row replaced before it started is voided.">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Subscription timeline">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Subscription</th><th scope="col" class="pe-4">State</th><th scope="col" class="pe-4">Plan version</th><th scope="col" class="pe-4">From</th><th scope="col" class="pe-4">Last day</th><th scope="col" class="pe-4">By</th><th scope="col" class="pe-4">Reason</th><th scope="col">Reference</th></tr></thead>
                <tbody>
                    @forelse ($this->subscriptions() as $subscription)
                        @foreach ($subscription->periods as $period)
                            <tr class="border-t border-gray-100 dark:border-gray-800 {{ $period->voided_at ? 'text-gray-500 dark:text-gray-400' : '' }}">
                                <td class="py-1 pe-4">#{{ $subscription->id }}</td>
                                <td class="pe-4">@if ($period->voided_at)<span class="line-through">{{ $period->status->value }}</span> <span class="italic">voided (never took effect)</span>@else<x-filament::badge size="sm" :color="$period->status->color()">{{ $period->status->value }}</x-filament::badge>@endif</td>
                                <td class="pe-4"><code>{{ $period->planVersion->label() }}</code></td>
                                <td class="pe-4">{{ $period->starts_on->toDateString() }}</td>
                                <td class="pe-4">{{ $period->ends_on?->toDateString() ?? 'open' }}</td>
                                <td class="pe-4">{{ $period->trigger }}</td>
                                <td class="pe-4">{{ $period->reason }}{{ $period->close_reason ? ' · closed: '.$period->close_reason : '' }}</td>
                                <td>{{ $period->reference }}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="8" class="py-2 text-gray-500 dark:text-gray-400">No subscription. The tenant keeps its SaaS.3/SaaS.4 behaviour (a manual plan assignment, or UNKNOWN); none is created by default.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Plan in force for entitlements (projected by the subscription)" collapsible collapsed>
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Projected plan assignments">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Assignment</th><th scope="col" class="pe-4">Subscription</th><th scope="col" class="pe-4">State</th><th scope="col" class="pe-4">Plan version</th><th scope="col" class="pe-4">From</th><th scope="col" class="pe-4">Last day</th><th scope="col">Row</th></tr></thead>
                <tbody>
                    @forelse ($this->assignments() as $a)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1 pe-4">#{{ $a->id }}</td><td class="pe-4">#{{ $a->subscription_id }}</td><td class="pe-4">{{ $a->commercial_status }}</td>
                            <td class="pe-4"><code>{{ $a->planVersion->label() }}</code></td><td class="pe-4">{{ $a->effective_from->toDateString() }}</td>
                            <td class="pe-4">{{ $a->effective_to?->toDateString() ?? 'open' }}</td><td>{{ $a->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-2 text-gray-500 dark:text-gray-400">None projected.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Audit trail (Markedge platform chain)" collapsible>
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Subscription audit trail">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">When (UTC)</th><th scope="col" class="pe-4">Action</th><th scope="col" class="pe-4">By</th><th scope="col" class="pe-4">Effective</th><th scope="col" class="pe-4">Changes</th><th scope="col">Reason</th></tr></thead>
                <tbody>
                    @forelse ($this->auditTrail() as $event)
                        <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                            <td class="py-1 pe-4 whitespace-nowrap">{{ $event->occurred_at?->toDateTimeString() }}</td>
                            <td class="pe-4">{{ $event->action->value }}</td>
                            <td class="pe-4">{{ $event->actor_name ?? 'scheduler' }}</td>
                            <td class="pe-4">{{ $event->effective_date?->toDateString() ?? '—' }}</td>
                            <td class="pe-4">@foreach ($event->fieldChanges as $change)<div><code>{{ $change->field }}</code>: {{ $change->before ?? '—' }} → {{ $change->after ?? '—' }}</div>@endforeach</td>
                            <td>{{ $event->reason }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-2 text-gray-500 dark:text-gray-400">No commercial changes recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="All tenants today" collapsible>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="All tenants today">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Tenant</th><th scope="col" class="pe-4">Technical status</th><th scope="col" class="pe-4">Commercial state</th><th scope="col" class="pe-4">Subscription</th><th scope="col" class="pe-4">Plan version</th><th scope="col">Period</th></tr></thead>
            <tbody>
                @foreach ($this->overview() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1 pe-4"><button type="button" wire:click="$set('tenant', {{ $row['tenant']->id }})" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row['tenant']->name }}</button></td>
                        <td class="pe-4">{{ $row['tenant']->status->getLabel() }}</td>
                        <td class="pe-4">@if ($row['state'])<x-filament::badge size="sm" :color="$row['state']['status']->color()">{{ $row['state']['status']->value }}</x-filament::badge>{{ $row['state']['derived'] ? ' (end passed)' : '' }}@else<span class="text-gray-500 dark:text-gray-400">none</span>@endif</td>
                        <td class="pe-4">{{ $row['subscription_id'] ? '#'.$row['subscription_id'] : '—' }}</td>
                        <td class="pe-4">{{ $row['version'] ?? '—' }}</td>
                        <td>{{ $row['state'] ? $row['state']['from'].' to '.($row['state']['to'] ?? 'open') : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
