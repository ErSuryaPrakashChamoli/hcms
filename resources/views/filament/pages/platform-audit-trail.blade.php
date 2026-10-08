<x-filament-panels::page>
    @php($status = $this->chainStatus())
    @php($events = $this->events())
    @php($selected = $this->selectedEvent())
    <x-filament::section>
        <div class="text-sm">
            <strong>Audit chains:</strong>
            <x-filament::badge size="sm" :color="match ($status['state']) { 'PASS' => 'success', 'FAIL' => 'danger', default => 'warning' }">{{ $status['state'] === 'PASS' ? 'INTACT' : ($status['state'] === 'FAIL' ? 'BROKEN' : 'NOT RECENTLY VERIFIED') }}</x-filament::badge>
            {{ $status['verified'] }} of {{ $status['chains'] }} chains verified{{ $status['oldest'] ? ', oldest result '.\Illuminate\Support\Carbon::parse($status['oldest'])->diffForHumans() : '' }}.
            @if ($status['broken'] !== [])<span class="text-danger-600 dark:text-danger-400">Broken: {{ implode(', ', $status['broken']) }}.</span>@endif
            Verified daily by <code>peopleos:audit:verify</code>.
        </div>
    </x-filament::section>

    <x-filament::section heading="Filter">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <label class="text-sm">Action
                <select wire:model.live="action" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="">Any</option>
                    @foreach ($this->actionOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm">Tenant concerned
                <select wire:model.live="subject" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="">Any</option>
                    @foreach ($this->tenantOptions() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm">From<input type="date" wire:model.live="from" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></label>
            <label class="text-sm">To<input type="date" wire:model.live="to" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></label>
            <div class="flex items-end"><x-filament::button color="gray" size="sm" wire:click="resetFilters">Clear filters</x-filament::button></div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Events" :description="$events->count().' most recent first'">
        @if ($events->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">No platform events match.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">Platform audit events</caption>
                    <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-4">When</th><th scope="col" class="pe-4">Action</th><th scope="col" class="pe-4">Operator</th><th scope="col" class="pe-4">Concerning</th><th scope="col">Reason</th></tr></thead>
                    <tbody>
                        @foreach ($events as $e)
                            <tr @class(['border-t border-gray-100 dark:border-gray-800', 'bg-primary-50 dark:bg-white/5' => $selected && $selected->id === $e->id])>
                                <td class="py-1 pe-4">{{ $e->occurred_at->format('Y-m-d H:i') }}</td>
                                <td class="pe-4"><button type="button" class="text-start text-primary-600 underline dark:text-primary-400" wire:click="$set('event', '{{ $e->id }}')">{{ $this->actionLabel($e) }}</button></td>
                                <td class="pe-4">{{ $e->actor_name ?? 'System' }}</td>
                                <td class="pe-4">{{ $e->metadata['subject_tenant'] ?? $e->entity_label ?? '—' }}</td>
                                <td class="text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit((string) $e->reason, 80) ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($events->count() >= $this->limit && $this->limit < 500)
                <div class="mt-2"><x-filament::button color="gray" size="sm" wire:click="showMore">Show more</x-filament::button></div>
            @endif
        @endif
    </x-filament::section>

    @if ($selected)
        <x-filament::section :heading="$this->actionLabel($selected).' · '.$selected->occurred_at->format('Y-m-d H:i:s')">
            <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[12rem_1fr]">
                <dt class="text-gray-500">Operator</dt><dd>{{ $selected->actor_name ?? 'System' }}</dd>
                <dt class="text-gray-500">Module</dt><dd>{{ $selected->module }}</dd>
                <dt class="text-gray-500">Concerning</dt><dd>{{ $selected->entity_label ?? '—' }}</dd>
                <dt class="text-gray-500">Reason</dt><dd>{{ $selected->reason ?? '—' }}</dd>
                <dt class="text-gray-500">Source / request</dt><dd>{{ $selected->source }} · {{ $selected->request_id ?? '—' }}</dd>
                <dt class="text-gray-500">Details</dt><dd class="break-all font-mono text-xs">{{ json_encode($selected->metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</dd>
            </dl>
            @if ($selected->fieldChanges->isNotEmpty())
                <table class="mt-3 w-full text-sm">
                    <caption class="sr-only">Changed values</caption>
                    <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-4">Field</th><th scope="col" class="pe-4">Before</th><th scope="col">After</th></tr></thead>
                    <tbody>
                        @foreach ($selected->fieldChanges as $change)
                            <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pe-4">{{ $change->field }}</td><td class="pe-4">{{ $change->before ?? '—' }}</td><td>{{ $change->after ?? '—' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
