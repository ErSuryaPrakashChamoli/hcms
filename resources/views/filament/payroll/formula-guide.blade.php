@php
    $code = 'rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[0.8rem] [font-variant-ligatures:none] text-gray-900 dark:bg-white/10 dark:text-gray-100';
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 align-top text-sm text-gray-700 dark:text-gray-200';
@endphp
<div class="space-y-6 text-sm text-gray-700 dark:text-gray-200">
    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">How salary formulas work</h3>
        <ol class="list-decimal space-y-1.5 pl-5">
            <li>A component is either a <strong>fixed amount</strong>, set on each employee’s compensation, or a <strong>formula</strong>. A structure row’s <em>formula override</em> replaces the component’s formula in that structure only. It works on fixed components too.</li>
            <li>Rows are calculated <strong>top to bottom by sort order</strong>, like a spreadsheet column. A formula can use the components <strong>above</strong> it by their lowercase code (<code class="{{ $code }}">basic</code>, <code class="{{ $code }}">hra</code>), the structure’s <strong>parameters</strong>, and the variables below.</li>
            <li>Every amount is <strong>monthly and for a full month</strong>. Payroll prorates afterwards for joining, exit and loss of pay when the component has “Prorated for LOP” on, so do not multiply a prorated component by <code class="{{ $code }}">paid_days</code> yourself.</li>
            <li>A result below zero is paid as 0. Wrap a balancing component in <code class="{{ $code }}">max(0, …)</code>.</li>
            <li>Text values go in quotes and match ignoring case: <code class="{{ $code }}">grade == "M2"</code>.</li>
            <li><strong>Statutory lines are not formulas.</strong> {{ implode(', ', $statutory) }} are worked out by the compliance engine from each component’s flags (PF wages, ESI wages, taxable) and the rules in force. A formula cannot compute or override them. <code class="{{ $code }}">pf_employer</code> gives the employer-PF estimate for balancing to CTC.</li>
            <li>To change a structure, create a <strong>new version</strong>, edit the draft, check it with <strong>Preview</strong>, then <strong>submit</strong>. Another person approves it. Approved versions never change, and payroll uses the version in force on each day. Formula mistakes are caught when the draft is saved.</li>
        </ol>
    </section>

    @if ($example)
        @php
            $money = fn ($value) => number_format((float) $value, 2);
            $percent = fn (float $rate) => rtrim(rtrim(number_format($rate * 100, 2), '0'), '.').'%';
            $t = $example['preview']['totals'];
            $rows = collect($example['preview']['rows'])->filter(fn (array $row) => $row['monthly'] !== null);
            [$pf, $esi, $lop] = [$example['pf'], $example['esi'], $example['lop']];
            $amount = $td.' text-right tabular-nums whitespace-nowrap';
            $total = 'bg-gray-50 font-semibold text-gray-950 dark:bg-white/5 dark:text-white';
            $first = $rows->first();
        @endphp
        <section class="space-y-3">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Worked example: CTC {{ $money($example['ctc_annual']) }} a year</h3>
            <p>The <strong>Standard structure</strong> every tenant starts with, for an annual CTC of {{ $money($example['ctc_annual']) }}, which is {{ $money($t['ctc_monthly']) }} a month. Fixed amounts on the employee’s compensation: {{ collect($example['values'])->map(fn ($value, $code) => $code.' = '.$money($value))->implode(', ') }}. A full {{ $example['days'] }}-day month, covered by PF on the wage ceiling, with the statutory rules in force on {{ $example['on']->format('j M Y') }}. <strong>Preview</strong> on the structure gives the same numbers.</p>

            <h4 class="font-semibold text-gray-950 dark:text-white">1. The formulas, top to bottom</h4>
            <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                    <thead><tr><th class="{{ $th }}">#</th><th class="{{ $th }}">Component</th><th class="{{ $th }}">Formula</th><th class="{{ $th }}">Working</th><th class="{{ $th }} text-right">Monthly</th><th class="{{ $th }} text-right">Annual</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="{{ $td }} tabular-nums">{{ $row['sort_order'] }}</td>
                                <td class="{{ $td }}">{{ $row['name'] }} <span class="text-gray-500">({{ $row['code'] }})</span></td>
                                <td class="{{ $td }}">@if ($row['formula'])<code class="{{ $code }}">{{ $row['formula'] }}</code>@else Fixed amount @endif</td>
                                <td class="{{ $td }}">@isset($example['working'][$row['code']])<code class="{{ $code }}">{{ $example['working'][$row['code']] }}</code>@else Set on the employee’s compensation @endisset</td>
                                <td class="{{ $amount }}">{{ $money($row['monthly']) }}</td>
                                <td class="{{ $amount }}">{{ $money($row['annual']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="list-disc space-y-1.5 pl-5">
                <li>Each row uses only what is above it: {{ $rows->skip(1)->first()['name'] ?? '' }} uses <code class="{{ $code }}">{{ strtolower($first['code']) }}</code> because {{ $first['name'] }} comes first. The last row takes everything above it away from monthly CTC, so the breakup adds up to CTC exactly.</li>
                @if ($pf)
                    <li><code class="{{ $code }}">pf_employer</code> is {{ $percent($pf['employer_rate']) }} of the PF wages ({{ implode(', ', $example['pf_components']) ?: 'none' }}: {{ $money($pf['wages']) }}){{ $pf['wages'] > $pf['base'] ? ', capped at the '.$money($pf['wage_ceiling']).' wage ceiling' : ', which is within the '.$money($pf['wage_ceiling']).' wage ceiling' }}: {{ $percent($pf['employer_rate']) }} × {{ $money($pf['base']) }} = {{ $money($pf['employer']) }}.</li>
                @else
                    <li>No EPF rule is in force on {{ $example['on']->format('j M Y') }}, so <code class="{{ $code }}">pf_employer</code> is 0 here.</li>
                @endif
            </ul>

            <h4 class="font-semibold text-gray-950 dark:text-white">2. The salary breakup</h4>
            <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                    <thead><tr><th class="{{ $th }}">Line</th><th class="{{ $th }} text-right">Monthly</th><th class="{{ $th }} text-right">Annual</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows->whereIn('type', ['earning', 'reimbursement']) as $row)
                            <tr><td class="{{ $td }}">{{ $row['name'] }} ({{ $row['code'] }})</td><td class="{{ $amount }}">{{ $money($row['monthly']) }}</td><td class="{{ $amount }}">{{ $money($row['annual']) }}</td></tr>
                        @endforeach
                        <tr class="{{ $total }}"><td class="{{ $td }}">Gross earnings</td><td class="{{ $amount }}">{{ $money($t['gross']) }}</td><td class="{{ $amount }}">{{ $money($t['gross'] * 12) }}</td></tr>
                        <tr>
                            <td class="{{ $td }}">+ PF (employer), PF_ER: {{ $pf ? $percent($pf['employer_rate']).' of '.$money($pf['base']) : 'no EPF rule in force' }}</td>
                            <td class="{{ $amount }}">{{ $money($t['pf_employer']) }}</td><td class="{{ $amount }}">{{ $money($t['pf_employer'] * 12) }}</td>
                        </tr>
                        <tr class="{{ $total }}">
                            <td class="{{ $td }}">Cost to company (CTC){{ abs($t['difference']) >= 1 ? ', '.$money(abs($t['difference'])).($t['difference'] > 0 ? ' short of' : ' over').' monthly CTC' : '' }}</td>
                            <td class="{{ $amount }}">{{ $money($t['breakup']) }}</td><td class="{{ $amount }}">{{ $money($t['breakup'] * 12) }}</td>
                        </tr>
                        @if ($t['deductions'] > 0)
                            <tr><td class="{{ $td }}">− Other deductions (non-statutory)</td><td class="{{ $amount }}">{{ $money($t['deductions']) }}</td><td class="{{ $amount }}">{{ $money($t['deductions'] * 12) }}</td></tr>
                        @endif
                        <tr>
                            <td class="{{ $td }}">− PF (employee), PF_EE: {{ $pf ? $percent($pf['employee_rate']).' of '.$money($pf['base']) : 'no EPF rule in force' }}</td>
                            <td class="{{ $amount }}">{{ $money($pf['employee'] ?? 0) }}</td><td class="{{ $amount }}">{{ $money(($pf['employee'] ?? 0) * 12) }}</td>
                        </tr>
                        <tr>
                            <td class="{{ $td }}">− ESI (employee), ESI_EE: {{ match (true) { $esi === null => 'no ESI rule in force', $esi['covered'] => $percent($esi['employee_rate']).' of '.$money($esi['wages']), default => 'not deducted, because ESI wages of '.$money($esi['wages']).' are above the '.$money($esi['wage_ceiling']).' ceiling' } }}</td>
                            <td class="{{ $amount }}">{{ $money($esi['employee'] ?? 0) }}</td><td class="{{ $amount }}">{{ $money(($esi['employee'] ?? 0) * 12) }}</td>
                        </tr>
                        <tr class="{{ $total }}"><td class="{{ $td }}">Take-home before professional tax and TDS</td><td class="{{ $amount }}">{{ $money($example['take_home']) }}</td><td class="{{ $amount }}">{{ $money($example['take_home'] * 12) }}</td></tr>
                    </tbody>
                </table>
            </div>
            <ul class="list-disc space-y-1.5 pl-5">
                <li><strong>Professional tax</strong> (PT) and <strong>income tax</strong> (TDS) also come off take-home. PT follows the slab of the employee’s work state, and some states have none. TDS follows the tax regime, the employee’s declarations and pay so far this year. Neither is shown here.</li>
                <li>Payroll also records <strong>PF admin and EDLI charges</strong> (PF_ADMIN) as an employer cost. The standard Special allowance does not take them out of CTC.</li>
                @if ($first && isset($lop['amounts'][$first['code']]))
                    <li><strong>Loss of pay:</strong> with {{ $lop['lop_days'] }} days of loss of pay in a {{ $example['days'] }}-day month, {{ $lop['paid_days'] }} days are paid. Each prorated component is paid for {{ $lop['paid_days'] }}/{{ $example['days'] }} of the month, so {{ $first['name'] }} {{ $money($first['monthly']) }} becomes {{ $money($lop['amounts'][$first['code']]) }} and gross earnings {{ $money($t['gross']) }} become {{ $money($lop['gross']) }}. The formulas still work on full-month amounts. This uses calendar days; payroll settings can count working days or a fixed number of days instead.</li>
                @endif
            </ul>
        </section>
    @endif

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Parameters</h3>
        <p>Named numbers on a structure version, e.g. <code class="{{ $code }}">basic_pct = 50</code> and <code class="{{ $code }}">min_basic = 15000</code>. Write the formula once (<code class="{{ $code }}">max(ctc_monthly * basic_pct / 100, min_basic)</code>) and give each structure, or each new version, its own numbers. Names use lowercase letters, digits and <code class="{{ $code }}">_</code>.</p>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Pay and month values</h3>
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                <thead><tr><th class="{{ $th }}">Name</th><th class="{{ $th }}">Value</th><th class="{{ $th }}">Example</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($pay as $name => [$description, $example])
                        <tr><td class="{{ $td }}"><code class="{{ $code }}">{{ $name }}</code></td><td class="{{ $td }}">{{ $description }}</td><td class="{{ $td }}"><code class="{{ $code }}">{{ $example }}</code></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Employee values</h3>
        <p class="pos-caption">Read from the employee’s position on the last day paid in the month. Empty text, or 0, when not recorded.</p>
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                <thead><tr><th class="{{ $th }}">Name</th><th class="{{ $th }}">Kind</th><th class="{{ $th }}">Value</th><th class="{{ $th }}">Example</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($employee as $name => [$kind, $description, $example])
                        <tr><td class="{{ $td }}"><code class="{{ $code }}">{{ $name }}</code></td><td class="{{ $td }}">{{ $kind }}</td><td class="{{ $td }}">{{ $description }}</td><td class="{{ $td }}"><code class="{{ $code }}">{{ $example }}</code></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Operators and functions</h3>
        <p><code class="{{ $code }}">+ - * / %</code>, comparisons <code class="{{ $code }}">&lt; &lt;= &gt; &gt;= == !=</code>, <code class="{{ $code }}">&amp;&amp;</code> (and), <code class="{{ $code }}">||</code> (or), and parentheses. A comparison gives 1 (true) or 0 (false). A division by zero fails the formula only if its result is used, so <code class="{{ $code }}">if(paid_days &gt; 0, x / paid_days, 0)</code> is safe.</p>
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                <thead><tr><th class="{{ $th }}">Function</th><th class="{{ $th }}">Gives</th><th class="{{ $th }}">Example</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($functions as [$signature, $description, $example])
                        <tr><td class="{{ $td }} whitespace-nowrap"><code class="{{ $code }}">{{ $signature }}</code></td><td class="{{ $td }}">{{ $description }}</td><td class="{{ $td }}"><code class="{{ $code }}">{{ $example }}</code></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Common patterns</h3>
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                <thead><tr><th class="{{ $th }}">For</th><th class="{{ $th }}">Formula</th><th class="{{ $th }}">Where</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($recipes as [$purpose, $formula, $where])
                        <tr><td class="{{ $td }}">{{ $purpose }}</td><td class="{{ $td }}"><code class="{{ $code }}">{{ $formula }}</code></td><td class="{{ $td }}">{{ $where }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Adding and changing components</h3>
        <ul class="list-disc space-y-1.5 pl-5">
            <li><strong>New pay element</strong> (e.g. LTA, shift allowance): create it under <em>Payroll → Salary components</em>. Pick its type, whether it is fixed or a formula, and its flags (taxable, PF wages, ESI wages, part of CTC / gross, prorated). The flags decide statutory treatment, so set them with your payroll adviser.</li>
            <li><strong>Use it in a structure</strong>: open the structure, create a new version, add the component to the draft with a sort order below everything its formula uses, and add an override if this structure calculates it differently.</li>
            <li><strong>Different clients or groups, different rules</strong>: keep one component catalogue and give each structure its own overrides and parameters. Narrow each version to the companies or grades it is for.</li>
            <li>Editing a component’s formula in the catalogue changes it for every structure that has no override. To change one structure only, use an override in a new version, which goes through approval.</li>
        </ul>
    </section>
</div>
