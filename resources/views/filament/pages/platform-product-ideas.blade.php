<x-filament-panels::page>
    @php($counts = $this->counts())
    @php($selected = $this->selected())

    <x-filament::section heading="Ideas" description="What organisations asked Markedge to build or improve, newest first (the latest 100 that match). Choose a reference to read the idea and answer it.">
        <div class="mb-3 flex flex-wrap items-end gap-4 text-sm" role="group" aria-label="Filter product ideas">
            <label class="flex flex-col gap-1">
                <span class="text-gray-500 dark:text-gray-400">Organisation</span>
                <select wire:model.live="tenant" class="fi-input rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Any</option>
                    @foreach ($this->tenantOptions() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-gray-500 dark:text-gray-400">Module</span>
                <select wire:model.live="module" class="fi-input rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Any</option>
                    @foreach ($this->moduleOptions() as $space => $modules)
                        <optgroup label="{{ $space }}">
                            @foreach ($modules as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-gray-500 dark:text-gray-400">Status</span>
                <select wire:model.live="status" class="fi-input rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Any ({{ array_sum($counts) }})</option>
                    @foreach ($this->statusOptions() as $value => $label)<option value="{{ $value }}">{{ $label }} ({{ $counts[$value] ?? 0 }})</option>@endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-gray-500 dark:text-gray-400">Kind</span>
                <select wire:model.live="kind" class="fi-input rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Any</option>
                    @foreach ($this->kindOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-gray-500 dark:text-gray-400">Reference or words</span>
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="IDEA-000042" class="fi-input rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
            </label>
        </div>
        @if ($this->rows()->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">No product ideas match.</p>
        @else
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Product ideas">
                <table class="w-full text-sm">
                    <caption class="sr-only">Product ideas, newest first</caption>
                    <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-4">Reference</th><th scope="col" class="pe-4">Organisation</th><th scope="col" class="pe-4">Idea</th><th scope="col" class="pe-4">Module · process</th><th scope="col" class="pe-4">Kind</th><th scope="col" class="pe-4">Status</th><th scope="col">Sent</th></tr></thead>
                    <tbody>
                        @foreach ($this->rows() as $row)
                            <tr @class(['border-t border-gray-100 align-top dark:border-gray-800', 'bg-primary-50 dark:bg-white/5' => $selected && $selected->id === $row->id])>
                                <td class="py-1 pe-4"><button type="button" class="font-mono text-primary-600 underline dark:text-primary-400" wire:click="$set('idea', {{ $row->id }})">{{ $row->reference }}</button></td>
                                <td class="pe-4">{{ $row->tenant?->name ?? '—' }}</td>
                                <td class="pe-4 [overflow-wrap:anywhere]">{{ $row->title }}</td>
                                <td class="pe-4">{{ \App\Domain\ProductFeedback\Services\ProductIdeas::moduleLabel($row->module) }} · {{ $row->process }}</td>
                                <td class="pe-4">{{ $row->kind?->getLabel() }}</td>
                                <td class="pe-4"><x-filament::badge size="sm" :color="$row->status->getColor()">{{ $row->status->getLabel() }}</x-filament::badge></td>
                                <td class="whitespace-nowrap">{{ $row->submitted_at?->format('Y-m-d') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    @if ($selected)
        <x-filament::section :heading="$selected->reference.' · '.$selected->title" description="As the organisation wrote it. Your status and response are shown to the organisation.">
            <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[14rem_1fr]">
                <dt class="text-gray-500">Status</dt><dd><x-filament::badge size="sm" :color="$selected->status->getColor()">{{ $selected->status->getLabel() }}</x-filament::badge>@if ($selected->status_changed_at) since {{ $selected->status_changed_at->format('Y-m-d H:i') }}@endif</dd>
                <dt class="text-gray-500">Organisation</dt><dd>{{ $selected->tenant?->name ?? '—' }}@if ($selected->tenant) ({{ $selected->tenant->slug }}, {{ $selected->tenant->status?->getLabel() }})@endif</dd>
                <dt class="text-gray-500">Sent by</dt><dd>{{ $selected->submitter?->name ?? 'no longer a user' }}@if ($selected->submitter?->email) · {{ $selected->submitter->email }}@endif · {{ $selected->submitted_at?->format('Y-m-d H:i') }}</dd>
                <dt class="text-gray-500">Module · process</dt><dd>{{ \App\Domain\ProductFeedback\Services\ProductIdeas::moduleLabel($selected->module) }} · {{ $selected->process }}</dd>
                <dt class="text-gray-500">Kind</dt><dd>{{ $selected->kind?->question() }}</dd>
                <dt class="text-gray-500">The challenge</dt><dd class="whitespace-pre-line [overflow-wrap:anywhere]">{{ $selected->challenge }}</dd>
                <dt class="text-gray-500">How it is managed today</dt><dd class="whitespace-pre-line [overflow-wrap:anywhere]">{{ $selected->current_workaround ?? '—' }}</dd>
                <dt class="text-gray-500">The ideal solution</dt><dd class="whitespace-pre-line [overflow-wrap:anywhere]">{{ $selected->ideal_solution }}</dd>
                <dt class="text-gray-500">How much it affects the work</dt><dd>{{ $selected->priorityLabel() ?? '—' }}</dd>
                <dt class="text-gray-500">People affected (about)</dt><dd>{{ $selected->impact_people !== null ? number_format($selected->impact_people) : '—' }}</dd>
                <dt class="text-gray-500">Files</dt>
                <dd>@forelse ($selected->attachmentNames() as $i => $name)<div>{{ $name }} {{ ($this->downloadAttachmentAction)(['index' => $i]) }}</div>@empty — @endforelse</dd>
                <dt class="text-gray-500">Markedge's response</dt><dd class="whitespace-pre-line [overflow-wrap:anywhere]">{{ $selected->platform_response ?? '—' }}</dd>
            </dl>
            <div class="mt-4 flex flex-wrap gap-2">{{ $this->changeStatusAction }}</div>
        </x-filament::section>
    @endif

</x-filament-panels::page>
