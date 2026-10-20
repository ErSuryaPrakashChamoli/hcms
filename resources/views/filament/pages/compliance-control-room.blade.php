<x-filament-panels::page>
    @php($rules = $this->getRuleSummary())
    @php($returns = $this->getReturnSummary())
    <div class="pos-panel pos-panel-pad pos-figures">
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ ($rules['verified'] ?? 0) === 0 ? 'bad' : '' }}">{{ $rules['verified'] ?? 0 }}</span><span class="pos-figure-label">Rule versions verified</span><span class="pos-figure-delta">{{ $rules['review'] ?? 0 }} in review · {{ $rules['draft'] ?? 0 }} draft</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $returns['blocking'] > 0 ? 'bad' : '' }}">{{ $returns['blocking'] }}</span><span class="pos-figure-label">Returns with blocking issues</span></div>
        <div class="pos-figure"><span class="pos-figure-value">{{ $returns['awaiting_approval'] }}</span><span class="pos-figure-label">Awaiting approval</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $returns['exported_not_filed'] > 0 ? 'warning' : '' }}">{{ $returns['exported_not_filed'] }}</span><span class="pos-figure-label">Exported, not yet filed</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $returns['reconciliation_required'] > 0 ? 'bad' : '' }}">{{ $returns['reconciliation_required'] }}</span><span class="pos-figure-label">Reconciliation required</span><span class="pos-figure-delta">{{ $returns['certificates_to_issue'] }} TDS certificate(s) to issue</span></div>
    </div>

    @php($ready = $this->getReadinessSummary())
    <div class="pos-panel pos-panel-pad pos-figures">
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $ready['required_verified'] < $ready['required_total'] ? 'bad' : '' }}">{{ $ready['required_verified'] }} / {{ $ready['required_total'] }}</span><span class="pos-figure-label">Required rules verified</span><span class="pos-figure-delta">for this tenant's active establishments</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $ready['notices']->isNotEmpty() ? 'bad' : '' }}">{{ $ready['notices']->count() }}</span><span class="pos-figure-label">Open regulatory notices</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $ready['layouts_verified'] < $ready['layouts_total'] ? 'warning' : '' }}">{{ $ready['layouts_verified'] }} / {{ $ready['layouts_total'] }}</span><span class="pos-figure-label">Export layouts verified</span></div>
        <div class="pos-figure"><span class="pos-figure-value">{{ $ready['establishments_verified'] }} / {{ $ready['establishments_total'] }}</span><span class="pos-figure-label">Establishments / registrations verified</span><span class="pos-figure-delta">registrations {{ $ready['registrations_verified'] }} / {{ $ready['registrations_total'] }}</span></div>
        <div class="pos-figure"><span class="pos-figure-value">{{ $ready['eligible_returns'] }}</span><span class="pos-figure-label">Returns passing the production gate</span><span class="pos-figure-delta">parallel runs reconciled {{ $ready['parallel_reconciled'] }} · in progress {{ $ready['parallel_open'] }}</span></div>
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
