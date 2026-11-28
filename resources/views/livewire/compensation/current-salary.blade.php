{{--
    Employee 360 → Rewards → Current salary (owner, 2026-11-27; App\Livewire\Compensation\CurrentSalary). Redesigned the
    same day ("it looks like a rough note"): a pay card first (assigned or not, the annual CTC, the month, the structure,
    the actions, and where the month's CTC goes), then the month in three boxes (earnings, deductions, employer) with the
    sum spelt out, then statutory and checks side by side, then how the salary moved since joining. The rows of that
    story are the Salary history list under this panel.
--}}
@php
    $F = \App\Filament\Support\Compensation\SalaryFormat::class;
    $snap = $s['snapshot'] ?? null;
    $next = $s['next'] ?? null;
    $cur = $snap?->currency ?? ($next['currency'] ?? 'INR');
    $money = fn ($value) => $F::money($value === null ? null : (float) $value, $cur);
    $pct = fn ($value) => $F::percent($value === null ? null : (float) $value);
    $pay = $s['pay'] ?? null;
    $a = $s['history']['analysis'] ?? null;
    $state = $snap ? 'assigned' : ($next ? 'scheduled' : 'none');
    $actions = collect([$this->assignSalaryAction, $this->reviseSalaryAction, $this->correctSalaryAction, $this->printBreakupAction])->filter(fn ($action) => $action->isVisible());

    // Where the month's CTC goes: take-home, deductions, employer contributions (and reimbursements when there are any).
    $split = [];
    $equation = [];
    $grossBase = 1.0;
    if ($pay) {
        $reimbursements = round(array_sum(array_column($pay['reimbursements'], 'amount')), 2);
        $split = array_values(array_filter([
            ['part' => 'net', 'label' => 'Take-home', 'amount' => max(0.0, $pay['net'])],
            ['part' => 'deductions', 'label' => 'Deductions', 'amount' => $pay['deductions_total']],
            $reimbursements > 0 ? ['part' => 'reimbursements', 'label' => 'Reimbursements', 'amount' => $reimbursements] : null,
            ['part' => 'employer', 'label' => 'Employer contributions', 'amount' => $pay['employer_total']],
        ]));
        $whole = max(0.01, array_sum(array_column($split, 'amount')));
        foreach ($split as $i => $part) {
            $split[$i]['share'] = round($part['amount'] / $whole * 100, 1);
        }
        $ctcParts = round($pay['gross'] + $reimbursements + $pay['employer_total'], 2);
        $equation = [
            'net' => [['Gross pay', $pay['gross']], ['−'], ['Deductions', $pay['deductions_total']], ['='], ['Take-home', $pay['net'], true]],
            'ctc' => array_values(array_filter([['Gross pay', $pay['gross']], $reimbursements > 0 ? ['+'] : null, $reimbursements > 0 ? ['Reimbursements', $reimbursements] : null,
                ['+'], ['Employer contributions', $pay['employer_total']], ['='], ['CTC a month', $pay['ctc_monthly'], true]])),
            'gap' => round($pay['ctc_monthly'] - $ctcParts, 2),
        ];
        $grossBase = max(0.01, $pay['gross']);
    }

    // The statutory estimate in one sentence per state, rather than a line per scheme.
    $byState = collect($s['figures']['estimate']['schemes'] ?? [])->groupBy('state')->map(fn ($group) => $group->pluck('label')->all())->all();
    $checkTitles = ['guardrail' => 'Wage share', 'wage_share' => 'Code on Wages', 'minimum_wage' => 'Minimum wage', 'salary' => 'Salary checks'];
    $checkIcon = ['attention' => 'heroicon-m-exclamation-triangle', 'ok' => 'heroicon-m-check-circle'];
@endphp
<div class="pos-sal" data-current-salary data-salary-level="{{ $s['level'] }}">
    @if ($s['level'] === 'self')
        <x-pos.state variant="empty" size="inline" title="Your salary and its breakup are on My compensation." why="What you see there is set by your organisation."
            :action="$s['myUrl'] ? 'Open My compensation' : null" :action-url="$s['myUrl']" />
    @else
        {{-- The pay card: assigned or not, said once, with the year, the month and what to do --}}
        <section class="pos-paycard" data-state="{{ $state }}" data-salary-state="{{ $state === 'none' ? 'not_assigned' : $state }}" aria-labelledby="paycard-title">
            <div class="pos-paycard-top">
                <div class="pos-paycard-head">
                    <h2 id="paycard-title" class="pos-paycard-eyebrow">Current salary</h2>
                    <span class="pos-paycard-pill" data-state="{{ $state }}">
                        <x-filament::icon :icon="match ($state) { 'assigned' => 'heroicon-m-check-circle', 'scheduled' => 'heroicon-m-clock', default => 'heroicon-m-exclamation-circle' }" class="size-4" />
                        {{ match ($state) { 'assigned' => 'Salary assigned', 'scheduled' => 'Starts '.$next['from']->format('j M Y'), default => 'No salary assigned' } }}
                    </span>
                </div>
                @if ($s['historyUrl'] || $s['auditUrl'])
                    <nav class="pos-paycard-links" aria-label="Salary records">
                        @if ($s['historyUrl'])
                            <a href="{{ $s['historyUrl'] }}"><x-filament::icon icon="heroicon-m-clock" class="size-4" />Salary history</a>
                        @endif
                        @if ($s['auditUrl'])
                            <a href="{{ $s['auditUrl'] }}" wire:navigate><x-filament::icon icon="heroicon-m-shield-check" class="size-4" />Audit trail</a>
                        @endif
                    </nav>
                @endif
            </div>

            <div class="pos-paycard-main">
                <div class="pos-paycard-figure">
                    @if ($snap)
                        <p class="pos-paycard-label">Annual CTC</p>
                        <p class="pos-paycard-amount" data-ctc>{{ $money($snap->ctcAnnual) }}</p>
                        <p class="pos-paycard-sub">
                            <span><strong>{{ $money($snap->monthlyCtc()) }}</strong> a month</span>
                            <span>{{ $s['structure'] }}</span>
                            <span>Since {{ $snap->effectiveFrom->format('j M Y') }}</span>
                            @if ($snap->variableTargetAnnual)
                                <span>Variable target {{ $money($snap->variableTargetAnnual) }} a year</span>
                            @endif
                        </p>
                    @elseif ($next)
                        <p class="pos-paycard-label">Annual CTC from {{ $next['from']->format('j M Y') }}</p>
                        <p class="pos-paycard-amount">{{ $money($next['ctc']) }}</p>
                        <p class="pos-paycard-sub"><span>{{ $next['structure'] }}</span><span>Nothing is in force today</span></p>
                    @else
                        <p class="pos-paycard-amount is-text">No salary yet</p>
                        <p class="pos-paycard-sub"><span>Payroll pays nothing without one. Give the structure and the CTC — or the gross or net pay — and a reviewer, an approver and an executor decide it.</span></p>
                    @endif
                </div>
                @if ($actions->isNotEmpty())
                    <div class="pos-paycard-actions">
                        @foreach ($actions as $action)
                            {{ $action }}
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($split !== [])
                <div class="pos-paysplit" data-salary-figures>
                    <p class="pos-paysplit-title">Where {{ $money($pay['ctc_monthly']) }} a month goes</p>
                    <div class="pos-paysplit-bar" role="img" aria-label="{{ collect($split)->map(fn ($p) => $p['label'].' '.$money($p['amount']))->implode(', ') }}">
                        @foreach ($split as $part)
                            @if ($part['share'] > 0)
                                <span data-part="{{ $part['part'] }}" style="--w: {{ $part['share'] }}"></span>
                            @endif
                        @endforeach
                    </div>
                    <ul class="pos-paysplit-legend">
                        @foreach ($split as $part)
                            <li data-part="{{ $part['part'] }}">
                                <span class="pos-paysplit-dot" aria-hidden="true"></span>
                                <span class="pos-paysplit-name">{{ $part['label'] }}</span>
                                <strong>{{ $money($part['amount']) }}</strong>
                                <span class="pos-paysplit-share">{{ rtrim(rtrim(number_format($part['share'], 1), '0'), '.') }}%</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        @if ($s['open'])
            @php
                $open = $s['open'];
                $decided = in_array($open->status, ['approved', 'scheduled'], true);
            @endphp
            <x-pos.step-banner :tone="$decided ? 'idle' : 'pending'" data-salary-open="{{ $open->status }}"
                :title="($decided ? 'Approved · takes effect on ' : 'A salary change is waiting · from ').$open->effective_from->format('j M Y')"
                :sub="$open->typeLabel().' · '.$s['openStatus'].' · annual CTC '.$F::money((float) $open->ctc_annual, $open->currency).($s['openPercent'] !== null ? ' ('.$pct($s['openPercent']).')' : '').($decided ? '' : ' · finish or cancel it before another change')">
                <x-slot:aside>
                    @if ($s['requestsUrl'])
                        <a href="{{ $s['requestsUrl'] }}" class="pos-btn pos-btn-secondary pos-btn-sm">Open the request</a>
                    @endif
                </x-slot:aside>
            </x-pos.step-banner>
        @endif

        @if ($pay)
            {{-- The month, in three boxes --}}
            <div class="pos-paysection">
                <div class="pos-paysection-head">
                    <h3 class="pos-paysection-title">Breakup a month</h3>
                    <p class="pos-caption">A full month as of {{ today()->format('j M Y') }}. Payroll prorates for days paid and works out income tax (TDS).</p>
                </div>
                <div class="pos-paygrid">
                    <section class="pos-paybox" data-kind="earnings" aria-labelledby="pb-earnings">
                        <header class="pos-paybox-head">
                            <span class="pos-paybox-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-banknotes" class="size-5" /></span>
                            <h4 id="pb-earnings" class="pos-paybox-title">Earnings</h4>
                            <span class="pos-paybox-count">{{ count($pay['earnings']) }}</span>
                        </header>
                        <ul class="pos-paybox-list">
                            @foreach ($pay['earnings'] as $code => $line)
                                <li data-line="{{ $code }}">
                                    <span class="pos-paybox-name">{{ $line['name'] }}</span>
                                    <span class="pos-paybox-amount">{{ $money($line['amount']) }}</span>
                                    <span class="pos-paybox-share" style="--pct: {{ round($line['amount'] / $grossBase * 100, 1) }}" title="{{ round($line['amount'] / $grossBase * 100, 1) }}% of gross pay" aria-hidden="true"><span></span></span>
                                </li>
                            @endforeach
                            @foreach ($pay['reimbursements'] as $code => $line)
                                <li data-line="{{ $code }}"><span class="pos-paybox-name">{{ $line['name'] }} <em>reimbursement</em></span><span class="pos-paybox-amount">{{ $money($line['amount']) }}</span></li>
                            @endforeach
                        </ul>
                        <footer class="pos-paybox-foot"><span>Gross pay</span><strong>{{ $money($pay['gross']) }}</strong></footer>
                    </section>

                    <section class="pos-paybox" data-kind="deductions" aria-labelledby="pb-deductions">
                        <header class="pos-paybox-head">
                            <span class="pos-paybox-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-receipt-percent" class="size-5" /></span>
                            <h4 id="pb-deductions" class="pos-paybox-title">Deductions</h4>
                            <span class="pos-paybox-count">{{ count($pay['deductions']) }}</span>
                        </header>
                        @if ($pay['deductions'] === [])
                            <div class="pos-paybox-empty">
                                <p class="pos-paybox-empty-title">Nothing deducted yet</p>
                                <p class="pos-caption">{{ $pay['complete'] ? 'No deduction applies to this salary.' : 'PF, ESI, PT and LWF appear here once their statutory rules are verified.' }}</p>
                            </div>
                        @else
                            <ul class="pos-paybox-list">
                                @foreach ($pay['deductions'] as $code => $line)
                                    <li data-line="{{ $code }}"><span class="pos-paybox-name">{{ $line['name'] }}</span><span class="pos-paybox-amount">{{ $money($line['amount']) }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                        <footer class="pos-paybox-foot"><span>Total deductions</span><strong>{{ $money($pay['deductions_total']) }}</strong></footer>
                    </section>

                    <section class="pos-paybox" data-kind="employer" aria-labelledby="pb-employer">
                        <header class="pos-paybox-head">
                            <span class="pos-paybox-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-building-office-2" class="size-5" /></span>
                            <h4 id="pb-employer" class="pos-paybox-title">Employer contributions</h4>
                            <span class="pos-paybox-count">{{ count($pay['employer']) }}</span>
                        </header>
                        @if ($pay['employer'] === [])
                            <div class="pos-paybox-empty"><p class="pos-paybox-empty-title">None</p><p class="pos-caption">Nothing is paid by the employer on top of gross pay.</p></div>
                        @else
                            <ul class="pos-paybox-list">
                                @foreach ($pay['employer'] as $code => $line)
                                    <li data-line="{{ $code }}"><span class="pos-paybox-name">{{ $line['name'] }}</span><span class="pos-paybox-amount">{{ $money($line['amount']) }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                        <footer class="pos-paybox-foot"><span>Total employer contributions</span><strong>{{ $money($pay['employer_total']) }}</strong></footer>
                    </section>
                </div>

                {{-- The month spelt out --}}
                <div class="pos-payequation" aria-label="How the month adds up">
                    @foreach (['net', 'ctc'] as $row)
                        <div class="pos-payequation-row" data-equation="{{ $row }}">
                            @foreach ($equation[$row] as $term)
                                @if (count($term) === 1)
                                    <span class="pos-payequation-op" aria-hidden="true">{{ $term[0] }}</span>
                                @else
                                    <span @class(['pos-payequation-term', 'is-key' => $term[2] ?? false])><em>{{ $term[0] }}</em><strong>{{ $money($term[1]) }}</strong></span>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                    @if (abs($equation['gap']) >= 1)
                        <p class="pos-caption pos-payequation-note">The structure leaves {{ $money(abs($equation['gap'])) }} {{ $equation['gap'] > 0 ? 'of the CTC unallocated' : 'more than the CTC' }}: check its balancing component.</p>
                    @endif
                    @unless ($pay['complete'])
                        <p class="pos-caption pos-payequation-note">Take-home is before PF, ESI, PT and LWF until their statutory rules are verified.</p>
                    @endunless
                </div>

                <details class="pos-sal-details">
                    <summary>How each component is worked out (the structure's formulas)</summary>
                    @include('filament.payroll.structure-preview', ['result' => $s['figures']['preview'], 'failure' => null])
                </details>
            </div>

            {{-- Statutory and checks, side by side --}}
            <div class="pos-paygrid is-two">
                <section class="pos-paybox" data-kind="statutory" aria-labelledby="pb-statutory" data-salary-statutory>
                    <header class="pos-paybox-head">
                        <span class="pos-paybox-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-scale" class="size-5" /></span>
                        <h4 id="pb-statutory" class="pos-paybox-title">Statutory</h4>
                        @if ($s['statutoryEdit'])
                            <a href="{{ $s['statutoryEdit'] }}" class="pos-link pos-paybox-link" wire:navigate>Edit statutory details</a>
                        @endif
                    </header>
                    @if ($s['statutory'])
                        @php
                            $st = $s['statutory'];
                        @endphp
                        <div class="pos-statchips">
                            @foreach ($st['applies'] as $scheme => $apply)
                                <span class="pos-statchip" data-state="{{ $apply['state'] }}" data-statutory-chip="{{ $scheme }}">
                                    <x-filament::icon :icon="match ($apply['state']) { 'yes' => 'heroicon-m-check-circle', 'no' => 'heroicon-m-x-circle', default => 'heroicon-m-minus-circle' }" class="size-4" />
                                    <strong>{{ $scheme }}</strong>
                                    <span>{{ match ($apply['state']) { 'yes' => 'Applies', 'no' => 'Does not apply', default => 'Not set' } }}{{ $apply['default'] ? ' (default)' : '' }}</span>
                                </span>
                            @endforeach
                        </div>
                        <dl class="pos-statfacts">
                            <div><dt>PT state</dt><dd>{{ $st['pt_state'] ?? 'Not set' }}</dd></div>
                            <div><dt>Tax regime</dt><dd>{{ $st['regime'] ?? 'Not set' }}</dd></div>
                            @foreach ($st['ids'] as $label => $onFile)
                                <div><dt>{{ $label }}</dt><dd><span class="pos-statbadge" data-on-file="{{ $onFile ? 'yes' : 'no' }}">{{ $onFile ? 'On file' : 'Missing' }}</span></dd></div>
                            @endforeach
                        </dl>
                    @else
                        <p class="pos-caption">Statutory details need employee.sensitive.view.</p>
                    @endif
                    <div class="pos-statnote" data-estimate>
                        @if (! empty($byState['estimated']))
                            <p data-tone="ok"><x-filament::icon icon="heroicon-m-check-circle" class="size-4" /><span>Estimated from verified rules: {{ implode(', ', $byState['estimated']) }}.</span></p>
                        @endif
                        @if (! empty($byState['no_rule']))
                            <p data-tone="warning"><x-filament::icon icon="heroicon-m-information-circle" class="size-4" /><span>Not estimated yet (no verified rule): {{ implode(', ', $byState['no_rule']) }}.</span></p>
                        @endif
                        @if (! empty($byState['not_due']))
                            <p data-tone="muted"><x-filament::icon icon="heroicon-m-calendar" class="size-4" /><span>Not due this month: {{ implode(', ', $byState['not_due']) }}.</span></p>
                        @endif
                        @if (! empty($byState['not_applicable']))
                            <p data-tone="muted"><x-filament::icon icon="heroicon-m-minus-circle" class="size-4" /><span>Does not apply: {{ implode(', ', $byState['not_applicable']) }}.</span></p>
                        @endif
                    </div>
                </section>

                <section class="pos-paybox" data-kind="checks" aria-labelledby="pb-checks">
                    <header class="pos-paybox-head">
                        <span class="pos-paybox-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-clipboard-document-check" class="size-5" /></span>
                        <h4 id="pb-checks" class="pos-paybox-title">Checks and payment</h4>
                    </header>
                    <ul class="pos-paychecks" data-checks>
                        @foreach ($s['figures']['checks'] as $check)
                            <li data-check="{{ $check['check'] }}" data-tone="{{ $check['state'] === 'attention' ? 'warning' : ($check['state'] === 'ok' ? 'ok' : 'muted') }}">
                                <x-filament::icon :icon="$checkIcon[$check['state']] ?? 'heroicon-m-information-circle'" class="size-5" />
                                <div><strong>{{ $checkTitles[$check['check']] ?? 'Check' }}</strong><span>{{ $check['text'] }}</span></div>
                            </li>
                        @endforeach
                    </ul>
                    <dl class="pos-statfacts">
                        <div><dt>Paid by</dt><dd>{{ $s['figures']['pay_mode']['label'] }}</dd></div>
                        <div><dt>Variable pay target</dt><dd>{{ $snap->variableTargetAnnual ? $money($snap->variableTargetAnnual).' a year' : 'None' }}</dd></div>
                        @foreach ($s['plans'] as $plan)
                            <div><dt>{{ $plan['name'] }}</dt><dd>{{ $money($plan['total']) }} over {{ $plan['months'] }} {{ \Illuminate\Support\Str::plural('month', $plan['months']) }}</dd></div>
                        @endforeach
                    </dl>
                </section>
            </div>
        @elseif ($s['failure'])
            <x-pos.state variant="partial" size="inline" title="The breakup cannot be shown." :why="$s['failure']" />
        @endif

        {{-- How the salary moved since joining (the rows are the Salary history below) --}}
        @if ($a && $a['count'] > 0)
            <section class="pos-paybox pos-payhistory" data-kind="history" aria-labelledby="pb-history" data-salary-analysis>
                <header class="pos-paybox-head">
                    <span class="pos-paybox-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-arrow-trending-up" class="size-5" /></span>
                    <h4 id="pb-history" class="pos-paybox-title">Revisions since joining</h4>
                    <span class="pos-caption pos-payhistory-meta">{{ collect([
                        $a['joined'] ? 'Joined '.$a['joined']->format('j M Y') : null,
                        $a['exited'] ? 'left '.$a['exited']->format('j M Y') : null,
                        $a['service'],
                    ])->filter()->implode(' · ') }}</span>
                </header>
                @if ($a['revisions'] === 0)
                    <div class="pos-paybox-empty" data-analysis="none">
                        <p class="pos-paybox-empty-title">Not revised yet</p>
                        <p class="pos-caption">The salary has been {{ $F::money((float) $a['current']['ctc'], $a['current']['currency']) }} a year since {{ $a['first']['from']->format('j M Y') }}. Each revision will show here with its change.</p>
                    </div>
                @else
                    <dl class="pos-payhistory-stats">
                        <div class="is-key" data-analysis="revisions"><dt>Revisions</dt><dd>{{ $a['revisions'] }}</dd>
                            <span>{{ $a['increases'] }} {{ \Illuminate\Support\Str::plural('increase', $a['increases']) }}{{ $a['decreases'] ? ' · '.$a['decreases'].' '.\Illuminate\Support\Str::plural('reduction', $a['decreases']) : '' }}</span></div>
                        <div data-analysis="total"><dt>Total growth</dt><dd>{{ $pct($a['total_percent']) }}</dd>
                            <span>{{ $a['first'] && $a['current'] ? $F::money((float) $a['first']['ctc'], $a['first']['currency']).' → '.$F::money((float) $a['current']['ctc'], $a['current']['currency']) : '' }}</span></div>
                        <div data-analysis="yearly"><dt>Growth a year</dt><dd>{{ $pct($a['yearly_percent']) }}</dd><span>{{ $a['yearly_percent'] === null ? 'After a year of salary history' : 'Compounded (CAGR)' }}</span></div>
                        <div data-analysis="average"><dt>Average hike</dt><dd>{{ $pct($a['average_percent']) }}</dd><span>{{ $a['average_gap_months'] !== null ? 'Every '.$a['average_gap_months'].' months on average' : '' }}</span></div>
                        <div data-analysis="highest"><dt>Highest hike</dt><dd>{{ $a['highest'] ? $pct($a['highest']['percent']) : '—' }}</dd><span>{{ $a['highest'] ? $a['highest']['type'].' · '.$a['highest']['from']->format('M Y') : '' }}</span></div>
                        <div data-analysis="last"><dt>Last revised</dt><dd>{{ $a['last_revised']?->format('M Y') ?? 'Not yet' }}</dd>
                            <span>{{ $a['months_since_last'] !== null ? $a['months_since_last'].' '.\Illuminate\Support\Str::plural('month', $a['months_since_last']).' ago' : '' }}</span></div>
                    </dl>
                    @if (count($a['series']) > 1)
                        <x-pos.sparkline class="pos-sal-spark" :values="array_column($a['series'], 'ctc')" :labels="array_column($a['series'], 'label')" label="Annual CTC" :height="64" />
                    @endif
                @endif
                <p class="pos-caption">Each revision — when, from what to what, by how much, and who proposed and approved it — is in the Salary history below, with a line-by-line Compare.</p>
            </section>
        @endif
    @endif

    <x-filament-actions::modals />
</div>
