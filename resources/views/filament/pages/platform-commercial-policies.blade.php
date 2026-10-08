<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300 max-w-4xl">Three kinds of commercial rule are kept apart. <strong>Statute</strong>: tax rules and statutory parameters, versioned with their official sources (tax rules are on Tax &amp; invoicing). <strong>Markedge policy</strong>: decisions such as payment terms or the notice before a price increase, shipped with a default and changed by an approved version. <strong>Customer contract</strong>: a customer's negotiated price and billing terms (Billing accounts). The calculations are code; every value they use is data, versioned and effective-dated, changed by one operator and approved by another. Nothing here changes an issued invoice.</p>
    </x-filament::section>

    @foreach (['company_policy' => ['Markedge policy', 'Values Markedge decided (the decision is shown). A change is a new version from a date.'], 'statutory' => ['Statutory parameters', 'Values set by law, per country, with their source. No default: a missing value is NOT CONFIGURED.']] as $domain => [$heading, $description])
        <x-filament::section :heading="$heading" :description="$description">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ $heading }}">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Setting</th><th scope="col" class="pe-4">In force today</th><th scope="col" class="pe-4">From</th><th scope="col">Versions</th></tr></thead>
                <tbody>
                    @foreach ($this->rows($domain) as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                            <td class="py-1 pe-4">{{ $row['key']->label() }}{{ $row['scope'] !== '' ? ' · '.$row['scope'] : '' }}<div class="text-xs text-gray-500 dark:text-gray-400"><code>{{ $row['key']->value }}</code> · {{ $row['key']->decision() }}</div></td>
                            <td class="pe-4 font-medium">{{ $row['value'] }}</td>
                            <td class="pe-4 text-xs">{{ $row['source'] }}</td>
                            <td class="text-xs">
                                <ul class="space-y-0.5">
                                    @forelse ($row['versions'] as $entry)
                                        @php($version = $entry['version'])
                                        <li><x-filament::badge size="sm" :color="static::stateColor($entry['state'])">{{ str_replace('_', ' ', $entry['state']) }}</x-filament::badge> v{{ $version->version }}: {{ $this->display($version) }} · {{ $version->effective_from->toDateString() }} to {{ $version->effective_to?->toDateString() ?? 'open' }} · {{ $version->reason }}{{ $version->source ? ' · '.$version->source.' '.$version->source_reference : '' }}</li>
                                    @empty
                                        <li class="text-gray-500 dark:text-gray-400">No version yet.</li>
                                    @endforelse
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-filament::section>
    @endforeach

    <x-filament::section heading="Change history" description="Every change to prices, deals, tax rules, policies and the statutory dataset (platform audit chain): who, what, why, before and after." collapsible>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Change history">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">When</th><th scope="col" class="pe-4">Who</th><th scope="col" class="pe-4">What</th><th scope="col" class="pe-4">Change</th><th scope="col">Why</th></tr></thead>
            <tbody>
                @forelse ($this->trail() as $event)
                    <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                        <td class="py-1 pe-4 text-xs">{{ $event->occurred_at?->toDateTimeString() }}</td>
                        <td class="pe-4 text-xs">{{ $event->actor?->name ?? 'system' }}</td>
                        <td class="pe-4 text-xs"><code>{{ $event->action->value }}</code><div>{{ $event->entity_label }}</div></td>
                        <td class="pe-4 text-xs">@foreach ($event->fieldChanges as $change)<div>{{ $change->field }}: @if ($change->is_sensitive) (sensitive, not shown) @else {{ \Illuminate\Support\Str::limit((string) ($change->before ?? '—'), 80) }} → {{ \Illuminate\Support\Str::limit((string) ($change->after ?? '—'), 80) }}@endif</div>@endforeach</td>
                        <td class="text-xs">{{ \Illuminate\Support\Str::limit((string) $event->reason, 160) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500 dark:text-gray-400">No configuration change recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
