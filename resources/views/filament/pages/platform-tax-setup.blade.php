<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300 max-w-4xl">Technical support is not legal compliance. A jurisdiction is at most <strong>configured</strong> here (determination built and a verified rule in force); "supported" needs a tax and legal approval outside PeopleOS. An invoice is refused whenever its tax cannot be determined safely, with the reason (TAX_CONFIGURATION_MISSING, TAX_RULE_UNVERIFIED, PLACE_OF_SUPPLY_UNRESOLVED, CUSTOMER_TAX_STATUS_UNRESOLVED, REGISTRATION_REQUIRED, EXPORT_CONDITIONS_NOT_SATISFIED, TAX_CLASSIFICATION_PENDING): no default tax, and never 0 % for missing configuration. Statutory values are versioned data with their sources: a change of law is a new rule version, verified by a second operator, from its effective date; issued invoices keep the rule they were issued under.</p>
    </x-filament::section>

    <x-filament::section heading="Jurisdiction support" description="What PeopleOS can say about each jurisdiction it describes. Countries not listed have no regime here and are not supported.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Jurisdiction support">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Jurisdiction</th><th scope="col" class="pe-4">Currency</th><th scope="col" class="pe-4">Tax regime</th><th scope="col" class="pe-4">Registration</th><th scope="col" class="pe-4">Customers (B2B / B2C)</th><th scope="col" class="pe-4">Determination</th><th scope="col" class="pe-4">Invoice requirements</th><th scope="col" class="pe-4">Payments</th><th scope="col">Status</th></tr></thead>
            <tbody>
                @foreach ($this->matrix() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                        <td class="py-1 pe-4 font-medium">{{ $row['name'] }}</td><td class="pe-4">{{ $row['currency'] }}</td><td class="pe-4">{{ $row['regime'] }}</td>
                        <td class="pe-4">{{ $row['registration'] }}</td><td class="pe-4">{{ $row['customers'] }}</td><td class="pe-4">{{ $row['determination'] }}</td>
                        <td class="pe-4">{{ $row['invoice_requirements'] }}</td><td class="pe-4">{{ $row['payments'] }}</td>
                        <td><x-filament::badge size="sm" :color="$row['status']->color()">{{ $row['status']->label() }}</x-filament::badge></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Markedge selling entities" description="Versioned; a version applies once a second operator approves it; an invoice copies the approved version in force on its issue date.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Selling entities">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Entity</th><th scope="col" class="pe-4">Version</th><th scope="col" class="pe-4">Legal name</th><th scope="col" class="pe-4">Jurisdiction</th><th scope="col" class="pe-4">Tax registration</th><th scope="col" class="pe-4">Registrations and undertakings</th><th scope="col">From</th></tr></thead>
            <tbody>
                @forelse ($this->suppliers() as $supplier)
                    <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pe-4"><code>{{ $supplier->entity_code }}</code></td><td class="pe-4">v{{ $supplier->version }} <x-filament::badge size="sm" :color="$supplier->status === 'approved' ? 'success' : ($supplier->status === 'pending' ? 'warning' : 'gray')">{{ $supplier->status === 'pending' ? 'PENDING APPROVAL' : strtoupper($supplier->status) }}</x-filament::badge></td><td class="pe-4">{{ $supplier->legal_name }}</td>
                        <td class="pe-4">{{ $supplier->subdivision ?? $supplier->country }}</td><td class="pe-4">{{ $supplier->tax_id_type?->label() }} {{ $supplier->tax_id_value ?? 'none' }}</td>
                        <td class="pe-4 text-xs">@forelse ($supplier->registrations ?? [] as $registration)<div>{{ $registration['type'] }} {{ $registration['reference'] }} ({{ $registration['valid_from'] }} to {{ $registration['valid_to'] ?? 'open' }})</div>@empty none @endforelse</td><td>{{ $supplier->effective_from->toDateString() }}</td></tr>
                @empty
                    <tr><td colspan="7" class="py-2 text-gray-500 dark:text-gray-400">No selling entity recorded. Markedge's entities and registrations are a business decision.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Statutory dataset" description="The current statutory values shipped with PeopleOS, researched against official sources. Loading is not a claim of legal compliance: Markedge's tax advisers remain responsible (B-8, B-9).">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Statutory dataset">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Dataset</th><th scope="col" class="pe-4">Researched on</th><th scope="col" class="pe-4">Rules shipped / loaded / verified / pending</th><th scope="col" class="pe-4">Parameters shipped / approved</th><th scope="col">Pending verification</th></tr></thead>
            <tbody>
                @forelse ($this->datasets() as $dataset)
                    <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                        <td class="py-1 pe-4 font-medium">{{ $dataset['version'] }}@if ($dataset['current']) <x-filament::badge size="sm" color="info">current</x-filament::badge>@endif @if (! $dataset['loaded']) <x-filament::badge size="sm" color="warning">not loaded</x-filament::badge>@endif</td>
                        <td class="pe-4">{{ $dataset['researched_on'] }}</td>
                        <td class="pe-4">{{ $dataset['shipped_rules'] }} / {{ $dataset['rules'] }} / {{ $dataset['verified'] }} / {{ $dataset['pending'] }}{{ $dataset['rejected'] ? ' · '.$dataset['rejected'].' rejected' : '' }}</td>
                        <td class="pe-4">{{ $dataset['shipped_parameters'] }} / {{ $dataset['parameters_approved'] }}</td>
                        <td class="text-xs">@forelse ($dataset['pending_items'] as $item)<div><code>{{ $item['label'] }}</code>: {{ $item['why'] }}</div>@empty — @endforelse</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500 dark:text-gray-400">No dataset shipped.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Tax rules" description="Versions per jurisdiction, newest first. Draft → pending verification → verified by a second operator (or rejected). A verified version is scheduled until its date, current while it is the latest started, then superseded (or expired). Only current rules apply; nothing falls back to an older version.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Tax rules">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Rule</th><th scope="col" class="pe-4">State</th><th scope="col" class="pe-4">Effective</th><th scope="col" class="pe-4">Outcomes</th><th scope="col" class="pe-4">Source</th><th scope="col">Verification</th></tr></thead>
            <tbody>
                @forelse ($this->rules() as $rule)
                    @php($state = $this->ruleState($rule))
                    <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                        <td class="py-1 pe-4"><code>{{ $rule->label() }}</code>@if ($rule->rule_code)<div class="text-xs text-gray-500 dark:text-gray-400">{{ $rule->rule_code }}</div>@endif @if ($rule->classification)<div class="text-xs">{{ collect($rule->classification)->map(fn ($v, $k) => $k.' '.$v)->implode(', ') }}</div>@else<div class="text-xs text-warning-700 dark:text-warning-400">classification pending</div>@endif</td>
                        <td class="pe-4"><x-filament::badge size="sm" :color="$state->color()">{{ $state->label() }}</x-filament::badge>@if ($rule->origin === 'dataset')<div class="text-xs text-gray-500 dark:text-gray-400">dataset {{ $rule->dataset_version }}{{ $rule->dataset_status === 'pending_verification' ? ', marked pending' : '' }}</div>@endif</td>
                        <td class="pe-4">{{ $rule->effective_from->toDateString() }} to {{ $rule->effective_to?->toDateString() ?? 'open' }}</td>
                        <td class="pe-4 text-xs">@foreach ($this->outcomeLines($rule) as $line)<div>{{ $line }}</div>@endforeach</td>
                        <td class="pe-4 text-xs">{{ $rule->source ?? '—' }}@if ($rule->source_reference)<div>{{ $rule->source_reference }}</div>@endif @if ($rule->source_url)<div><a class="text-primary-600 underline dark:text-primary-400" href="{{ $rule->source_url }}" target="_blank" rel="noopener noreferrer">official source</a>{{ $rule->source_date ? ' ('.$rule->source_date->toDateString().')' : '' }}</div>@endif</td>
                        <td class="text-xs">{{ $rule->verification_reference ?? '—' }}{{ $rule->verified_at ? ' · '.$rule->verified_at->toDateString() : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500 dark:text-gray-400">No tax rule. Load the statutory dataset (and have another operator verify it), or draft a rule from a tax review.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Local tax jurisdiction rules" description="Rules of one local tax jurisdiction (US county, city, district) inside a state, as your rate source codes it. Where a state rule requires local rates, the customer's billing profile must record its local jurisdiction and its rule must be verified: otherwise the invoice is refused, never taxed at the state rate alone." collapsible collapsed>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Local tax jurisdiction rules">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Rule</th><th scope="col" class="pe-4">State</th><th scope="col" class="pe-4">Effective</th><th scope="col" class="pe-4">Outcomes</th><th scope="col">Source</th></tr></thead>
            <tbody>
                @forelse ($this->localRules() as $rule)
                    @php($state = $this->ruleState($rule))
                    <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                        <td class="py-1 pe-4"><code>{{ $rule->label() }}</code>@if ($rule->rule_code)<div class="text-xs text-gray-500 dark:text-gray-400">{{ $rule->rule_code }}</div>@endif</td>
                        <td class="pe-4"><x-filament::badge size="sm" :color="$state->color()">{{ $state->label() }}</x-filament::badge></td>
                        <td class="pe-4">{{ $rule->effective_from->toDateString() }} to {{ $rule->effective_to?->toDateString() ?? 'open' }}</td>
                        <td class="pe-4 text-xs">@foreach ($this->outcomeLines($rule) as $line)<div>{{ $line }}</div>@endforeach</td>
                        <td class="text-xs">{{ $rule->source ?? '—' }} {{ $rule->source_reference }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500 dark:text-gray-400">No local rule. Draft one per local tax jurisdiction from your rate source (Draft tax rule, US sales tax, state and local jurisdiction code).</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>

    @foreach ($this->coverage() as $heading => $rows)
        <x-filament::section :heading="$heading" description="A place without a configured rule is PENDING VERIFICATION: invoices to customers there are refused, never taxed at 0 %." collapsible collapsed>
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ $heading }}">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Code</th><th scope="col" class="pe-4">Name</th><th scope="col" class="pe-4">State</th><th scope="col">Rule</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800 align-top"><td class="py-1 pe-4"><code>{{ $row['code'] }}</code></td><td class="pe-4">{{ $row['name'] }}</td>
                            <td class="pe-4"><x-filament::badge size="sm" :color="$row['color']">{{ $row['state'] }}</x-filament::badge></td><td class="text-xs">{{ $row['rule'] ? $row['rule'].' · ' : '' }}{{ $row['summary'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-filament::section>
    @endforeach

    <x-filament::section heading="Statutory parameters" description="Values set by law that are not tax rates (e.g. the maximum invoice-number length), versioned with their source.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Statutory parameters">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Parameter</th><th scope="col" class="pe-4">Scope</th><th scope="col" class="pe-4">Value</th><th scope="col" class="pe-4">Effective</th><th scope="col" class="pe-4">Status</th><th scope="col">Source</th></tr></thead>
            <tbody>
                @forelse ($this->parameters() as $parameter)
                    <tr class="border-t border-gray-100 dark:border-gray-800 align-top"><td class="py-1 pe-4"><code>{{ $parameter->key }}</code> v{{ $parameter->version }}</td><td class="pe-4">{{ $parameter->scope ?: 'all' }}</td>
                        <td class="pe-4">{{ is_scalar($parameter->typedValue()) ? var_export($parameter->typedValue(), true) : json_encode($parameter->typedValue()) }}</td>
                        <td class="pe-4">{{ $parameter->effective_from->toDateString() }} to {{ $parameter->effective_to?->toDateString() ?? 'open' }}</td>
                        <td class="pe-4">{{ $parameter->status }}</td><td class="text-xs">{{ $parameter->source }} {{ $parameter->source_reference }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500 dark:text-gray-400">No statutory parameter: load the statutory dataset.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Invoice number series" description="Numbers are allocated inside the issuing transaction: unique, consecutive, gap-free.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Invoice number series">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Entity</th><th scope="col" class="pe-4">Series</th><th scope="col" class="pe-4">Maximum length</th><th scope="col">Status</th></tr></thead>
            <tbody>
                @forelse ($this->series() as $series)
                    <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pe-4"><code>{{ $series->supplier_entity }}</code></td><td class="pe-4">{{ $series->label() }}</td><td class="pe-4">{{ $series->max_length ?? '—' }}</td><td>{{ $series->status }}</td></tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-gray-500 dark:text-gray-400">No series. The numbering format is a tax decision.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
