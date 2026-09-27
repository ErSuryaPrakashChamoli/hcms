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

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->getEstablishments() as $row)
            <x-filament::section :heading="$row['establishment']->name" :description="($row['establishment']->legalEntity?->legal_name ?? '') . ' · ' . ($row['establishment']->state ?? 'no state')">
                <dl class="grid grid-cols-2 gap-y-1 text-sm">
                    <dt class="text-gray-500">Statutes</dt>
                    <dd>{{ $row['profiles'] ?: 'None applicable' }}</dd>
                    <dt class="text-gray-500">Missing registrations</dt>
                    <dd class="{{ $row['missing'] ? 'text-danger-600' : '' }}">{{ $row['missing'] ? implode(', ', $row['missing']) : 'None' }}</dd>
                </dl>
            </x-filament::section>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
