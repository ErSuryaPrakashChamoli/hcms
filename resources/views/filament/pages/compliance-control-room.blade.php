<x-filament-panels::page>
    @php($rules = $this->getRuleSummary())
    @php($returns = $this->getReturnSummary())
    <div class="grid gap-4 md:grid-cols-5">
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Rule versions verified</div>
            <div class="text-2xl font-semibold {{ ($rules['verified'] ?? 0) === 0 ? 'text-danger-600' : '' }}">{{ $rules['verified'] ?? 0 }}</div>
            <div class="text-xs text-gray-500">{{ $rules['review'] ?? 0 }} in review · {{ $rules['draft'] ?? 0 }} draft</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Returns with blocking issues</div>
            <div class="text-2xl font-semibold {{ $returns['blocking'] > 0 ? 'text-danger-600' : '' }}">{{ $returns['blocking'] }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Awaiting approval</div>
            <div class="text-2xl font-semibold">{{ $returns['awaiting_approval'] }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Exported, not yet filed</div>
            <div class="text-2xl font-semibold {{ $returns['exported_not_filed'] > 0 ? 'text-warning-600' : '' }}">{{ $returns['exported_not_filed'] }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Reconciliation required</div>
            <div class="text-2xl font-semibold {{ $returns['reconciliation_required'] > 0 ? 'text-danger-600' : '' }}">{{ $returns['reconciliation_required'] }}</div>
            <div class="text-xs text-gray-500">{{ $returns['certificates_to_issue'] }} TDS certificate(s) to issue</div>
        </x-filament::section>
    </div>

    @php($ready = $this->getReadinessSummary())
    <div class="grid gap-4 md:grid-cols-5">
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Required rules verified</div>
            <div class="text-2xl font-semibold {{ $ready['required_verified'] < $ready['required_total'] ? 'text-danger-600' : '' }}">{{ $ready['required_verified'] }} / {{ $ready['required_total'] }}</div>
            <div class="text-xs text-gray-500">for this tenant's active establishments</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Open regulatory notices</div>
            <div class="text-2xl font-semibold {{ $ready['notices']->isNotEmpty() ? 'text-danger-600' : '' }}">{{ $ready['notices']->count() }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Export layouts verified</div>
            <div class="text-2xl font-semibold {{ $ready['layouts_verified'] < $ready['layouts_total'] ? 'text-warning-600' : '' }}">{{ $ready['layouts_verified'] }} / {{ $ready['layouts_total'] }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Establishments / registrations verified</div>
            <div class="text-2xl font-semibold">{{ $ready['establishments_verified'] }} / {{ $ready['establishments_total'] }}</div>
            <div class="text-xs text-gray-500">registrations {{ $ready['registrations_verified'] }} / {{ $ready['registrations_total'] }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Returns passing the production gate</div>
            <div class="text-2xl font-semibold">{{ $ready['eligible_returns'] }}</div>
            <div class="text-xs text-gray-500">parallel runs reconciled {{ $ready['parallel_reconciled'] }} · in progress {{ $ready['parallel_open'] }}</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Rules this tenant needs" description="Derived from active establishments, their statutory profiles and states.">
        <ul class="text-sm space-y-1">
            @forelse ($ready['required'] as $need)
                <li>
                    <span class="font-medium">{{ $need['statute'] }}{{ $need['state'] ? ' (' . $need['state'] . ')' : '' }}</span>
                    — {{ $need['rule']?->label() ?? 'no rule version' }}
                    <x-filament::badge :color="$need['status'] === 'verified' ? 'success' : 'danger'" class="inline-flex">{{ str_replace('_', ' ', $need['status']) }}</x-filament::badge>
                    <span class="text-gray-500">· {{ implode(', ', $need['establishments']) }}</span>
                    @if ($need['notice'])<div class="text-danger-600 text-xs">{{ $need['notice'] }}</div>@endif
                </li>
            @empty
                <li class="text-gray-500">No statute applies to an active establishment yet.</li>
            @endforelse
        </ul>
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->getEstablishments() as $row)
            <x-filament::section :heading="$row['establishment']->name" :description="($row['establishment']->legalEntity?->legal_name ?? '') . ' · ' . ($row['establishment']->state ?? 'no state')">
                <dl class="grid grid-cols-2 gap-y-1 text-sm">
                    <dt class="text-gray-500">Statutes</dt>
                    <dd>{{ $row['profiles'] ?: 'None applicable' }}</dd>
                    <dt class="text-gray-500">Verification</dt>
                    <dd class="{{ $row['establishment']->verification_status === 'verified' ? '' : 'text-danger-600' }}">{{ $row['establishment']->verification_status }}</dd>
                    <dt class="text-gray-500">Missing registrations</dt>
                    <dd class="{{ $row['missing'] ? 'text-danger-600' : '' }}">{{ $row['missing'] ? implode(', ', $row['missing']) : 'None' }}</dd>
                </dl>
            </x-filament::section>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
