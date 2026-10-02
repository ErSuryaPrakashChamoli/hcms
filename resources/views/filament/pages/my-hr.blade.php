<x-filament-panels::page>
    <x-filament::tabs label="My HR sections">
        @foreach (\App\Filament\Pages\MyHr::TABS as $key => $label)
            <x-filament::tabs.item :active="$tab === $key" wire:click="setTab('{{ $key }}')">{{ $label }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    @if ($tab === 'requests')
        <x-filament::section heading="My requests" description="Requests you raised and requests about you that HR has made visible to you.">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Number</th><th>Service</th><th>Status</th><th>Raised</th><th class="sr-only">Open</th></tr></thead>
                    <tbody>
                        @forelse ($this->getRequests() as $ticket)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="py-1 font-medium">{{ $ticket->number }}</td>
                                <td>{{ $ticket->serviceName() }}</td>
                                <td><x-filament::badge :color="\App\Filament\Resources\Tickets\TicketResource::statusColor($ticket->status)">{{ \App\Filament\Resources\Tickets\TicketResource::statusLabel($ticket->status) }}</x-filament::badge></td>
                                <td>{{ $ticket->created_at->diffForHumans() }}</td>
                                <td><x-filament::link :href="\App\Filament\Resources\Tickets\TicketResource::getUrl('view', ['record' => $ticket])">Open</x-filament::link></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-3 text-gray-500">You have no requests. Use <strong>Services</strong> to request something, or <strong>Ask HR</strong>.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @elseif ($tab === 'tasks')
        <x-filament::section heading="My tasks" description="Things waiting for you in PeopleOS. Each one opens where it is done.">
            <ul class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                @forelse ($this->getTasks() as $task)
                    <li class="flex items-center justify-between gap-4 py-2">
                        <span>{{ $task->title }} @if ($task->reference)<span class="text-gray-500">· {{ $task->reference }}</span>@endif</span>
                        <span class="flex items-center gap-3">
                            @if ($task->dueAt)<span @class(['text-xs', 'text-danger-600' => $task->isOverdue(), 'text-gray-500' => ! $task->isOverdue()])>Due {{ $task->dueAt->diffForHumans() }}</span>@endif
                            @if ($task->url)<x-filament::link :href="$task->url">Open</x-filament::link>@endif
                        </span>
                    </li>
                @empty
                    <li class="py-3 text-gray-500">Nothing is waiting for you.</li>
                @endforelse
            </ul>
            @php($attention = $this->getAttention())
            @if ($attention->isNotEmpty())
                <h3 class="mt-4 text-sm font-semibold">Needs attention</h3>
                <ul class="mt-1 text-sm">
                    @foreach ($attention as $item)
                        <li class="py-1">{{ $item['label'] ?? $item['title'] ?? '' }} @if (($item['count'] ?? 0) > 0)<x-filament::badge>{{ $item['count'] }}</x-filament::badge>@endif</li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @elseif ($tab === 'approvals')
        <x-filament::section heading="My approvals" description="Approvals assigned to you in the workflow engine.">
            <ul class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                @forelse ($this->getApprovals() as $task)
                    <li class="flex items-center justify-between py-2"><span>{{ $task->title }}</span>@if ($task->url)<x-filament::link :href="$task->url">Decide</x-filament::link>@endif</li>
                @empty
                    <li class="py-3 text-gray-500">No approvals are waiting for you.</li>
                @endforelse
            </ul>
        </x-filament::section>
    @elseif ($tab === 'documents')
        <x-filament::section heading="My documents" description="From your document record. Downloads use a short-lived private link and are audited.">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Document</th><th>Category</th><th>Status</th><th>Version</th><th>Expires</th><th class="sr-only">Download</th></tr></thead>
                    <tbody>
                        @forelse ($this->getDocuments() as $doc)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="py-1">{{ $doc->title }}</td>
                                <td>{{ config('peopleos.documents.categories.'.($doc->type?->category ?? 'other'), '—') }}</td>
                                <td>{{ ucfirst((string) $doc->status) }}</td>
                                <td>v{{ $doc->version }}</td>
                                <td>{{ $doc->expires_on?->toDateString() ?? '—' }}</td>
                                <td><x-filament::link :href="$this->documentUrl($doc)">Download</x-filament::link></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-3 text-gray-500">No documents yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <h3 class="mt-4 text-sm font-semibold">Letters</h3>
            <ul class="text-sm">
                @forelse ($this->getLetters() as $letter)
                    <li class="py-1">{{ $letter->number }} · {{ config('peopleos.letters.types.'.$letter->type, $letter->type) }} · {{ str_replace('_', ' ', $letter->status) }} @if ($letter->issued_at)(issued {{ $letter->issued_at->toDateString() }} — in your documents)@endif</li>
                @empty
                    <li class="py-1 text-gray-500">No letters. Request one from <strong>Services</strong>.</li>
                @endforelse
            </ul>
        </x-filament::section>
    @elseif ($tab === 'policies')
        @php($pending = $this->getPendingPolicyIds())
        <x-filament::section heading="Policies and articles" description="The published version of each article that applies to you.">
            <ul class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                @forelse ($this->getPolicies() as $article)
                    <li class="flex items-center justify-between gap-4 py-2">
                        <span><x-filament::link :href="\App\Filament\Resources\Articles\ArticleResource::getUrl('view', ['record' => $article])">{{ $article->title }}</x-filament::link> <span class="text-gray-500">v{{ $article->published_version }}</span></span>
                        @if (in_array($article->id, $pending, true))
                            <x-filament::button size="sm" wire:click="acknowledge({{ $article->id }})" wire:confirm="Confirm you have read and understood version {{ $article->published_version }} of {{ $article->title }}?">Acknowledge</x-filament::button>
                        @elseif ($article->requires_acknowledgement)
                            <x-filament::badge color="success">Acknowledged</x-filament::badge>
                        @endif
                    </li>
                @empty
                    <li class="py-3 text-gray-500">No articles are published for you yet.</li>
                @endforelse
            </ul>
        </x-filament::section>
    @elseif ($tab === 'services')
        <x-filament::section heading="HR services" description="What you can request now. Changes to your own records are applied by HR after any approval the service needs.">
            <div class="grid gap-3 md:grid-cols-2">
                @forelse ($this->getServices() as $version)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div class="font-medium">{{ $version->service->name }}</div>
                        @if ($version->description)<p class="mt-1 text-sm text-gray-500">{{ $version->description }}</p>@endif
                        <div class="mt-2">{{ ($this->requestServiceAction)(['service' => $version->service_definition_id, 'for' => 'self']) }}</div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No services are available to you yet. Use <strong>Ask HR</strong>.</p>
                @endforelse
            </div>
        </x-filament::section>
    @else
        <x-filament::section heading="Notifications">
            <ul class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                @forelse ($this->getNotifications() as $n)
                    <li class="py-2">{{ data_get($n->data, 'title', 'Notification') }} <span class="text-xs text-gray-500">· {{ $n->created_at->diffForHumans() }}</span></li>
                @empty
                    <li class="py-3 text-gray-500">No notifications.</li>
                @endforelse
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
