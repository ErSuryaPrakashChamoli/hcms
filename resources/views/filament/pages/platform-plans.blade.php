<x-filament-panels::page>
    @php($plans = $this->plans())
    @php($usage = $this->usage())
    <x-filament::section>
        <div class="flex flex-wrap items-end gap-4 text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-gray-500">Plan</span>
                <select wire:model.live="plan" class="fi-input rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="">All plans</option>
                    @foreach ($plans as $p)
                        <option value="{{ $p->id }}">{{ $p->code }} · {{ $p->name }}</option>
                    @endforeach
                </select>
            </label>
            <p class="text-gray-600 dark:text-gray-300 max-w-2xl">Shadow mode: a plan defines what a tenant's commercial answer would be. Nothing is enforced, and no tenant is on a plan until an operator assigns one on the Entitlements page. Published versions never change; tenants keep the version they are on.</p>
        </div>
    </x-filament::section>

    <x-filament::section heading="Catalogue">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Plan catalogue">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1">Code</th><th scope="col">Name</th><th scope="col">State today</th><th scope="col">On sale today</th><th scope="col">Versions</th><th scope="col">Tenants on it today</th></tr></thead>
            <tbody>
                @forelse ($plans as $p)
                    @php($state = $p->state())
                    @php($onSale = $p->versions->first(fn ($v) => $v->onSaleOn(now()->toDateString())))
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1"><button type="button" wire:click="$set('plan', {{ $p->id }})" class="font-mono text-primary-600 hover:underline dark:text-primary-400">{{ $p->code }}</button></td>
                        <td>{{ $p->name }}</td>
                        <td><x-filament::badge size="sm" :color="$state->color()">{{ $state->value }}</x-filament::badge></td>
                        <td>{{ $onSale ? 'v'.$onSale->version : '—' }}</td>
                        <td>{{ $p->versions->count() }}</td>
                        <td>{{ $p->versions->sum(fn ($v) => $usage[$v->id]['current'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500">No plans yet. Packaging (which capabilities form which plan, and every limit) is still an open commercial decision; nothing is created by default.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>

    @if ($current = $this->selectedPlan())
        @php($versions = $current->versions->sortByDesc('version'))
        <x-filament::section heading="{{ $current->code }} · {{ $current->name }}" :description="$current->description">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Plan versions">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1">Version</th><th scope="col">State today</th><th scope="col">On sale</th><th scope="col">Tenants today</th><th scope="col">Assigned from a later date</th><th scope="col">Published</th><th scope="col">What changed</th></tr></thead>
                <tbody>
                    @foreach ($versions as $v)
                        @php($vs = $v->state())
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1">v{{ $v->version }}</td>
                            <td><x-filament::badge size="sm" :color="$vs->color()">{{ $vs->value }}</x-filament::badge></td>
                            <td>{{ $v->effective_from ? $v->effective_from->toDateString().' to '.($v->effective_to?->toDateString() ?? 'open') : '—' }}</td>
                            <td>{{ $usage[$v->id]['current'] ?? 0 }}</td>
                            <td>{{ $usage[$v->id]['upcoming'] ?? 0 }}</td>
                            <td>{{ $v->published_at ? $v->published_at->toDateTimeString().' · '.($v->publisher?->name ?? '—') : '—' }}</td>
                            <td>{{ $v->change_note ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Capabilities by version" description="The capability catalogue is code-owned; a plan only says what it includes. Protected capabilities can be included or left out, never switched off. A capability not in the plan would be NOT_IN_PLAN for a tenant on it.">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Capabilities by version">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1">Capability</th><th scope="col">Type</th><th scope="col">Class</th>
                    @foreach ($versions as $v)<th scope="col">v{{ $v->version }} <span class="font-normal">({{ $v->state()->value }})</span></th>@endforeach
                </tr></thead>
                <tbody>
                    @foreach ($this->matrix($current) as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1"><code>{{ $row['capability']->value }}</code> <span class="text-gray-500">{{ $row['capability']->label() }}</span></td>
                            <td>{{ $row['capability']->type()->value }}</td>
                            <td>{{ str_replace('_', ' ', $row['capability']->enforcement()->value) }}</td>
                            @foreach ($versions as $v)
                                @php($cell = $row['values'][$v->id])
                                <td class="{{ $cell === 'not in plan' ? 'text-gray-500 dark:text-gray-400 italic' : '' }}">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Audit trail (Markedge platform chain)" collapsible>
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Plan audit trail">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1">When (UTC)</th><th scope="col">Action</th><th scope="col">By</th><th scope="col">Effective</th><th scope="col">Changes</th><th scope="col">Reason</th></tr></thead>
                <tbody>
                    @forelse ($this->history($current) as $event)
                        <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                            <td class="py-1 whitespace-nowrap">{{ $event->occurred_at?->toDateTimeString() }}</td>
                            <td>{{ $event->action->value }}@if (isset($event->metadata['plan_version'])) · v{{ $event->metadata['plan_version'] }}@endif</td>
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
                        <tr><td colspan="6" class="py-2 text-gray-500">No changes recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
