<x-filament-panels::page>
    {{-- The compliance control room in the step kit's language: returns and readiness as toned tiles (red where something
         blocks, amber where something waits, green when done), the rules the tenant needs as one row each with its status,
         and each establishment as a card. --}}
    @php
        $rules = $this->getRuleSummary();
        $returns = $this->getReturnSummary();
        $ready = $this->getReadinessSummary();
        $tone = fn (bool $bad, bool $warn = false) => $bad ? 'blocked' : ($warn ? 'warning' : 'clear');
    @endphp
    <div class="pos-step">
        <section class="pos-step-group" aria-label="Returns">
            <h2 class="pos-step-subhead">Returns</h2>
            <dl class="pos-step-stats">
                <div class="pos-step-stat" data-tone="{{ $tone(($rules['verified'] ?? 0) === 0) }}"><dt>Rule versions verified</dt><dd>{{ $rules['verified'] ?? 0 }}</dd><span class="pos-step-delta">{{ $rules['review'] ?? 0 }} in review · {{ $rules['draft'] ?? 0 }} draft</span></div>
                <div class="pos-step-stat" data-tone="{{ $tone($returns['blocking'] > 0) }}"><dt>Returns with blocking issues</dt><dd>{{ $returns['blocking'] }}</dd></div>
                <div class="pos-step-stat" data-tone="{{ $returns['awaiting_approval'] > 0 ? 'pending' : 'clear' }}"><dt>Awaiting approval</dt><dd>{{ $returns['awaiting_approval'] }}</dd></div>
                <div class="pos-step-stat" data-tone="{{ $tone(false, $returns['exported_not_filed'] > 0) }}"><dt>Exported, not yet filed</dt><dd>{{ $returns['exported_not_filed'] }}</dd></div>
                <div class="pos-step-stat" data-tone="{{ $tone($returns['reconciliation_required'] > 0) }}"><dt>Reconciliation required</dt><dd>{{ $returns['reconciliation_required'] }}</dd><span class="pos-step-delta">{{ $returns['certificates_to_issue'] }} TDS certificate(s) to issue</span></div>
            </dl>
        </section>

        <section class="pos-step-group" aria-label="Readiness">
            <h2 class="pos-step-subhead">Readiness</h2>
            <dl class="pos-step-stats">
                <div class="pos-step-stat" data-tone="{{ $tone($ready['required_verified'] < $ready['required_total']) }}"><dt>Required rules verified</dt><dd>{{ $ready['required_verified'] }} <small class="pos-step-stat-of">of {{ $ready['required_total'] }}</small></dd><span class="pos-step-delta">for this tenant's active establishments</span></div>
                <div class="pos-step-stat" data-tone="{{ $tone($ready['notices']->isNotEmpty()) }}"><dt>Open regulatory notices</dt><dd>{{ $ready['notices']->count() }}</dd></div>
                <div class="pos-step-stat" data-tone="{{ $tone(false, $ready['layouts_verified'] < $ready['layouts_total']) }}"><dt>Export layouts verified</dt><dd>{{ $ready['layouts_verified'] }} <small class="pos-step-stat-of">of {{ $ready['layouts_total'] }}</small></dd></div>
                <div class="pos-step-stat" data-tone="{{ $tone(false, $ready['establishments_verified'] < $ready['establishments_total']) }}"><dt>Establishments / registrations verified</dt><dd>{{ $ready['establishments_verified'] }} <small class="pos-step-stat-of">of {{ $ready['establishments_total'] }}</small></dd><span class="pos-step-delta">registrations {{ $ready['registrations_verified'] }} / {{ $ready['registrations_total'] }}</span></div>
                <div class="pos-step-stat" data-tone="idle"><dt>Returns passing the production gate</dt><dd>{{ $ready['eligible_returns'] }}</dd><span class="pos-step-delta">parallel runs reconciled {{ $ready['parallel_reconciled'] }} · in progress {{ $ready['parallel_open'] }}</span></div>
            </dl>
        </section>
    </div>

    <x-filament::section heading="Rules this tenant needs" description="Derived from active establishments, their statutory profiles and states.">
        <div class="pos-step">
            @if ($ready['required'] === [] || (is_countable($ready['required']) && count($ready['required']) === 0))
                <p class="pos-step-note">No statute applies to an active establishment yet.</p>
            @else
                <ul class="pos-step-rules">
                    @foreach ($ready['required'] as $need)
                        <li data-tone="{{ $need['status'] === 'verified' ? 'clear' : 'blocked' }}">
                            <span class="pos-step-rule-icon" aria-hidden="true"><x-filament::icon :icon="$need['status'] === 'verified' ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-circle'" class="pos-step-rule-svg" /></span>
                            <span class="pos-step-rule-text">
                                <strong>{{ $need['statute'] }}{{ $need['state'] ? ' (' . $need['state'] . ')' : '' }} — {{ $need['rule']?->label() ?? 'no rule version' }}</strong>
                                <span>{{ implode(', ', $need['establishments']) }}</span>
                                @if ($need['notice'])<span class="is-alert">{{ $need['notice'] }}</span>@endif
                            </span>
                            <span class="pos-step-pill" data-tone="{{ $need['status'] === 'verified' ? 'clear' : 'blocked' }}">{{ str_replace('_', ' ', $need['status']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </x-filament::section>

    <div class="pos-step-company-grid">
        @foreach ($this->getEstablishments() as $row)
            @php
                $verified = $row['establishment']->verification_status === 'verified';
            @endphp
            <x-filament::section :heading="$row['establishment']->name" :description="($row['establishment']->legalEntity?->legal_name ?? '') . ' · ' . ($row['establishment']->state ?? 'no state')">
                <div class="pos-step">
                    <span class="pos-step-pill" data-tone="{{ $verified ? 'clear' : 'blocked' }}">{{ $row['establishment']->verification_status }}</span>
                    <dl class="pos-step-ledger is-plain">
                        <div><dt>Statutes</dt><dd>{{ $row['profiles'] ?: 'None applicable' }}</dd></div>
                        <div><dt>Missing registrations</dt><dd @class(['is-danger' => $row['missing']])>{{ $row['missing'] ? implode(', ', $row['missing']) : 'None' }}</dd></div>
                    </dl>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
