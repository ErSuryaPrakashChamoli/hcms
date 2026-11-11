<x-filament-panels::page>
    {{-- The payroll overview in the step kit's language: the headline figures as toned tiles, each company's month as a card
         with its status, and the pre-run readiness as the pre-payroll checks are shown (a progress header, what needs a look
         as one line each — opened in place for its people and CSV — and what is clear or cannot be checked as chips). --}}
    @php
        $readiness = $this->getReadiness();
    @endphp
    <div class="pos-step">
        <dl class="pos-step-stats">
            <div class="pos-step-stat" data-tone="{{ $readiness['no_salary'] > 0 ? 'warning' : 'clear' }}"><dt>Employees without a salary</dt><dd>{{ $readiness['no_salary'] }}</dd></div>
            <div class="pos-step-stat" data-tone="{{ $readiness['no_bank'] > 0 ? 'warning' : 'clear' }}"><dt>Without a primary bank account</dt><dd>{{ $readiness['no_bank'] }}</dd></div>
            <div class="pos-step-stat" data-tone="{{ $readiness['no_pan'] > 0 ? 'warning' : 'clear' }}"><dt>Without PAN</dt><dd>{{ $readiness['no_pan'] }}</dd></div>
            <div class="pos-step-stat" data-tone="{{ $readiness['rules'] === 0 ? 'blocked' : 'idle' }}"><dt>Statutory rules in force</dt><dd>{{ $readiness['rules'] }}</dd></div>
        </dl>

        <div class="pos-step-company-grid">
            @foreach ($this->getCompanies() as $row)
                @php
                    $statusLabel = $row['status'] === 'not_started' ? 'Not started' : config('peopleos.payroll.run_statuses.'.$row['status'], $row['status']);
                    $statusTone = match ($row['status']) { 'not_started' => 'na', 'draft' => 'idle', 'finalized', 'paid' => 'clear', default => 'pending' };
                @endphp
                <x-filament::section :heading="$row['company']->name" :description="'Payroll for ' . $row['period_label'] . ' · ' . $row['period_dates']">
                    <div class="pos-step">
                        <div class="pos-step-group-head">
                            <span class="pos-step-pill" data-tone="{{ $statusTone }}">{{ $statusLabel }}</span>
                            @if ($row['url'])
                                <a href="{{ $row['url'] }}" class="pos-step-link">Open run<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-step-link-icon" /></a>
                            @endif
                        </div>
                        <dl class="pos-step-ledger is-plain">
                            <div><dt>Headcount</dt><dd>{{ $row['headcount'] }}</dd></div>
                            <div><dt>Without salary</dt><dd @class(['is-warning' => $row['without_salary'] > 0])>{{ $row['without_salary'] }}</dd></div>
                            <div><dt>Statutory profile</dt><dd>{{ $row['profile'] ? 'Configured' : 'Defaults (not configured)' }}</dd></div>
                            @if ($row['run'])
                                <div><dt>Net pay</dt><dd>{{ number_format($row['run']->total('net'), 2) }}</dd></div>
                                <div><dt>Exceptions</dt><dd @class(['is-warning' => $row['run']->exception_count > 0])>{{ $row['run']->exception_count }}</dd></div>
                            @endif
                        </dl>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    </div>

    {{-- HROne parity PO-3: pre-run readiness (read-only; informs, never blocks a run). Loads after the page. --}}
    <x-filament::section heading="Before you run payroll" description="Read-only checks for each company's pay month. They inform and never stop a run; statutory thresholds come only from verified rules.">
        <div wire:init="loadReadiness" class="pos-step">
            <div class="pos-step-pickers is-row">
                <label class="pos-step-picker">
                    <span>Company</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="readinessCompany">
                            <option value="">Every company</option>
                            @foreach ($this->readinessCompanies() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="pos-step-check">
                    <x-filament::input.checkbox wire:model.live="readinessActiveOnly" />
                    <span>Active employees only</span>
                </label>
            </div>

            @php
                $preRun = $this->readiness();
            @endphp
            @if ($preRun === null)
                <div class="pos-step-loading" role="status" wire:loading.remove><x-filament::loading-indicator class="pos-step-loading-icon" /> Checking…</div>
            @else
                @php
                    $checks = collect($preRun['checks']);
                    $look = $checks->filter(fn (array $c) => $c['state'] === 'attention');
                    $clear = $checks->filter(fn (array $c) => $c['state'] === 'ok');
                    $unchecked = $checks->filter(fn (array $c) => in_array($c['state'], ['no_rule', 'not_run'], true));
                    $total = $checks->count();
                    $fine = $clear->count() + $unchecked->count();
                    $pct = $total > 0 ? (int) round($fine * 100 / $total) : 100;
                    $tone = $look->isEmpty() ? 'clear' : 'warning';
                    $segments = array_filter(['warning' => [$look->count(), 'Needs a look'], 'clear' => [$clear->count(), 'Clear'], 'na' => [$unchecked->count(), 'Not checked']], fn (array $s) => $s[0] > 0);
                    $open = $readinessCheck !== null ? ($preRun['checks'][$readinessCheck] ?? null) : null;
                @endphp
                <div class="pos-prc" data-tone="{{ $tone }}">
                    <div class="pos-prc-head">
                        <div class="pos-prc-ring" style="--pct: {{ $pct }}" role="img" aria-label="{{ $fine }} of {{ $total }} checks need nothing">
                            <span><strong>{{ $fine }}</strong>/{{ $total }}</span>
                        </div>
                        <div class="pos-prc-head-body">
                            <p class="pos-prc-headline">{{ $look->isEmpty() ? 'Nothing to fix before the run' : $look->count().' '.\Illuminate\Support\Str::plural('check', $look->count()).' need a look' }}</p>
                            @if ($preRun['months'] !== [])
                                <p class="pos-prc-sub">{{ collect($preRun['months'])->map(fn ($m) => $m['company'].' · '.$m['label'].' ('.$m['dates'].')')->implode('; ') }}</p>
                            @endif
                            <div class="pos-prc-bar" aria-hidden="true">
                                @foreach ($segments as $key => [$n])<span data-tone="{{ $key }}" style="flex-grow: {{ $n }}"></span>@endforeach
                            </div>
                            <ul class="pos-prc-legend">
                                @foreach ($segments as $key => [$n, $label])<li data-tone="{{ $key }}"><span class="pos-prc-dot"></span>{{ $label }} <strong>{{ $n }}</strong></li>@endforeach
                            </ul>
                        </div>
                    </div>

                    @foreach ($preRun['notes'] as $note)
                        <p class="pos-step-note"><x-filament::icon icon="heroicon-m-information-circle" class="pos-step-note-icon" />{{ $note }}</p>
                    @endforeach

                    @if ($look->isNotEmpty())
                        <section class="pos-prc-group" aria-label="Needs a look">
                            <h3 class="pos-prc-group-title">Needs a look <span>{{ $look->count() }}</span></h3>
                            <ul class="pos-prc-list">
                                @foreach ($look as $key => $check)
                                    @php
                                        $isOpen = $readinessCheck === $key;
                                    @endphp
                                    <li @class(['pos-prc-item', 'is-open' => $isOpen]) wire:key="ready-{{ $key }}" data-check="{{ $key }}" data-tone="warning">
                                        <div class="pos-prc-row">
                                            <button type="button" class="pos-prc-toggle" wire:click="openReadinessCheck('{{ $key }}')" aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                                aria-label="{{ $check['label'] }}: {{ $check['count'] }}">
                                                <span class="pos-prc-tile" aria-hidden="true">{{ $check['count'] }}</span>
                                                <span class="pos-prc-main">
                                                    <span class="pos-prc-label">{{ $check['label'] }}</span>
                                                    <span class="pos-prc-meta is-clamped">{{ $check['description'] }}</span>
                                                </span>
                                                <x-filament::icon icon="heroicon-m-chevron-down" class="pos-prc-chevron" />
                                            </button>
                                            <div class="pos-prc-actions">
                                                <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadReadiness('{{ $key }}')">CSV</x-filament::button>
                                            </div>
                                        </div>
                                        @if ($isOpen && $open)
                                            <div class="pos-prc-detail">
                                                <div class="pos-prc-detail-inner">
                                                    @foreach ($open['notes'] as $note)<p class="pos-prc-desc">{{ $note }}</p>@endforeach
                                                    <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="{{ $open['label'] }}">
                                                        <table class="pos-step-table">
                                                            <thead><tr>@foreach ($open['columns'] as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
                                                            <tbody>
                                                                @foreach (array_slice($open['rows'], 0, 200) as $row)
                                                                    <tr>
                                                                        @foreach (array_keys($open['columns']) as $column)<td>{{ is_float($row[$column] ?? null) ? number_format($row[$column], 2) : ($row[$column] ?? '') }}</td>@endforeach
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    @if (count($open['rows']) > 200)<p class="pos-prc-more">Showing 200 of {{ count($open['rows']) }}; the CSV has every row.</p>@endif
                                                </div>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if ($clear->isNotEmpty())
                        <section class="pos-prc-group" aria-label="All clear">
                            <h3 class="pos-prc-group-title">All clear <span>{{ $clear->count() }}</span></h3>
                            <ul class="pos-prc-chips">
                                @foreach ($clear as $key => $check)
                                    <li data-check="{{ $key }}" data-tone="clear" title="{{ $check['description'] }}">
                                        <x-filament::icon icon="heroicon-m-check-circle" class="pos-prc-chip-icon" />
                                        <span>{{ $check['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if ($unchecked->isNotEmpty())
                        <section class="pos-prc-group" aria-label="Not checked">
                            <h3 class="pos-prc-group-title">Not checked <span>{{ $unchecked->count() }}</span></h3>
                            <ul class="pos-prc-chips">
                                @foreach ($unchecked as $key => $check)
                                    <li data-check="{{ $key }}" data-tone="na" title="{{ $check['description'] }}{{ $check['notes'] !== [] ? ' '.implode(' ', $check['notes']) : '' }}">
                                        <x-filament::icon icon="heroicon-m-minus-circle" class="pos-prc-chip-icon" />
                                        <span>{{ $check['label'] }}</span>
                                        <span class="pos-prc-chip-note">{{ $check['state'] === 'no_rule' ? 'No verified rule' : 'Not run' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </div>
            @endif
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
