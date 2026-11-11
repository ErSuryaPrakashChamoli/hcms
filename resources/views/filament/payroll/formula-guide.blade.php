@php
    $code = 'rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[0.8rem] [font-variant-ligatures:none] text-gray-900 dark:bg-white/10 dark:text-gray-100';
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 align-top text-sm text-gray-700 dark:text-gray-200';
    $box = 'whitespace-pre-wrap rounded-lg bg-gray-50 px-3 py-2 font-mono text-[0.8rem] [font-variant-ligatures:none] text-gray-900 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-100 dark:ring-white/10';
    $ways = [
        ['% of CTC', 'A share of the employee’s CTC. The CTC is yearly; payroll uses a twelfth of it each month.', 'CTC 6,00,000 a year is 50,000 a month, so Basic at 50% is 25,000 a month.', 'Basic is 50% of CTC'],
        ['% of another component', 'A share of a component, usually Basic. The builder works that component out first.', 'Basic 25,000 → HRA at 40% of Basic is 10,000.', 'HRA is 40% of Basic'],
        ['Fixed amount', 'The same amount for everyone on the structure, every month. A yearly amount is paid in twelve parts.', 'Conveyance 1,600 a month. Medical 15,000 a year is 1,250 a month.', 'Conveyance is a fixed 1,600 a month'],
        ['Entered per employee', 'You type the amount on each employee’s salary (or in the salary upload).', 'Food coupons: 2,200 for some, 1,100 for others.', 'Food coupons is entered for each employee'],
        ['Whatever is left of the CTC', 'CTC less every other component and the employer PF, so the total always equals the CTC. Use it once, usually for Special allowance.', 'CTC 50,000 − Basic 25,000 − HRA 10,000 − employer PF (say 1,800) → Special allowance 13,200.', 'Special allowance is whatever is left of the CTC'],
        ['Custom formula', 'For rules the other ways cannot say, e.g. an amount per grade or city. See section 6.', 'Grade G1 gets 1,500, everyone else 2,500.', 'Shift allowance = lookup(grade, "G1", 1500, 2500)'],
    ];
    $scenarios = [
        ['Simple CTC split (most companies)', "Basic is 50% of CTC\nHRA is 40% of Basic\nSpecial allowance is whatever is left of the CTC"],
        ['More allowances, with a floor on Basic', "Basic is 40% of CTC, at least 15,000\nHRA is 50% of Basic\nConveyance is a fixed 1,600 a month\nMedical allowance is 15,000 a year\nLTA is 8.33% of Basic\nSpecial allowance is whatever is left of the CTC"],
        ['With variable pay inside the CTC', "Basic is 50% of CTC\nHRA is 40% of Basic\nPerformance bonus is 10% of CTC, variable\nSpecial allowance is whatever is left of the CTC"],
        ['No CTC: amounts typed in for each person', "Basic is entered for each employee\nHRA is 40% of Basic\nSpecial allowance is entered for each employee"],
        ['Gratuity and a deduction as well', "Basic is 50% of CTC\nHRA is 40% of Basic\nGratuity is 4.81% of Basic (employer contribution)\nCanteen recovery is a fixed 500 a month (deduction)\nSpecial allowance is whatever is left of the CTC"],
    ];
@endphp
<div class="space-y-6 text-sm text-gray-700 dark:text-gray-200">
    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">1. Set up a structure in five steps</h3>
        <p>Open <em>Salary structures</em> and click <strong>New salary structure</strong>. The builder walks you through:</p>
        <ol class="list-decimal space-y-1.5 pl-5">
            <li><strong>Name and company.</strong> What it is called, which company it is for (or all), and the date it starts.</li>
            <li><strong>How pay is set.</strong> Either you give each employee a yearly <strong>CTC</strong> and the structure splits it (most companies), or you give an <strong>amount for each component</strong> and the CTC is added up.</li>
            <li><strong>Components.</strong> Pick each one from a list and choose how it is worked out, or write them in plain English (section 3). You can add components that are not in your list yet.</li>
            <li><strong>Check.</strong> Type a sample CTC and see the monthly and yearly breakup, and whether it adds up.</li>
            <li><strong>Save.</strong> Save a draft, or send it for approval. Someone with approval rights, other than you, approves it.</li>
        </ol>
        <p>After approval: give the structure to employees on their salary (or by salary upload) and run payroll. To change a structure later, open it again in the builder; the change starts on the date you pick and earlier months are not touched.</p>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">2. Six ways to work out a component</h3>
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                <thead><tr><th class="{{ $th }}">Way</th><th class="{{ $th }}">What it means</th><th class="{{ $th }}">Example</th><th class="{{ $th }}">In plain English</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($ways as [$way, $means, $sample, $english])
                        <tr><td class="{{ $td }} font-semibold">{{ $way }}</td><td class="{{ $td }}">{{ $means }}</td><td class="{{ $td }}">{{ $sample }}</td><td class="{{ $td }}"><code class="{{ $code }}">{{ $english }}</code></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p>A percentage can have a limit: <code class="{{ $code }}">at least 15,000</code> (a floor) or <code class="{{ $code }}">at most 20,000</code> (a cap), both monthly. Mark variable pay as <strong>Variable</strong>; everything else is fixed pay.</p>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">3. Writing it in plain English</h3>
        <ul class="list-disc space-y-1.5 pl-5">
            <li>One component per line, name first: <code class="{{ $code }}">HRA is 40% of Basic</code>. A list on one line works too: <code class="{{ $code }}">Basic 50% of CTC, HRA 40% of basic, conveyance 1600</code>.</li>
            <li>Amounts can be written <code class="{{ $code }}">1,600</code>, <code class="{{ $code }}">Rs 1600</code>, <code class="{{ $code }}">1.6k</code> or <code class="{{ $code }}">1.5 lakh</code>. Add <code class="{{ $code }}">a year</code> for yearly amounts.</li>
            <li>Say <code class="{{ $code }}">variable</code> for variable pay, <code class="{{ $code }}">(deduction)</code> for something taken off pay, and <code class="{{ $code }}">(employer contribution)</code> for a cost the company pays on top, such as gratuity.</li>
            <li>The usual names are recognised: HRA or house rent, conveyance or transport, LTA or leave travel, and so on. A name that is not in your component list is added as a new component when you save.</li>
            <li>Lines about PF, ESI, professional tax, TDS or LWF are skipped: payroll works those out from the statutory rules.</li>
            <li>Press <strong>Turn into components</strong>. Every line shows what it was read as, or what to change. Check the result, and fine-tune it under <em>Pick from a list</em> if you like.</li>
        </ul>
    </section>

    <section class="space-y-3">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">4. Examples for different companies</h3>
        <p>Copy one into the plain-English box and change the numbers to suit.</p>
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($scenarios as [$title, $text])
                <div class="space-y-1.5">
                    <h4 class="font-semibold text-gray-950 dark:text-white">{{ $title }}</h4>
                    <div class="{{ $box }}">{{ $text }}</div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">What payroll does for you</h3>
        <ul class="list-disc space-y-1.5 pl-5">
            <li><strong>Statutory amounts are automatic.</strong> {{ implode(', ', $statutory) }} are worked out by payroll from each component’s settings (PF wages, ESI wages, taxable) and the rules in force. Do not add them to a structure.</li>
            <li><strong>Unpaid days.</strong> Amounts are for a full month. Payroll reduces them for joining, exit and loss-of-pay days.</li>
            <li><strong>Nothing below zero.</strong> A component that works out below zero is paid as 0.</li>
        </ul>
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
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">5. Worked example: CTC {{ $money($example['ctc_annual']) }} a year</h3>
            <p>How payroll works a salary out, line by line. This is the <strong>Standard structure</strong> every company starts with, for an annual CTC of {{ $money($example['ctc_annual']) }}, which is {{ $money($t['ctc_monthly']) }} a month. Fixed amounts on the employee’s compensation: {{ collect($example['values'])->map(fn ($value, $code) => $code.' = '.$money($value))->implode(', ') }}. A full {{ $example['days'] }}-day month, covered by PF on the wage ceiling, with the statutory rules in force on {{ $example['on']->format('j M Y') }}. <strong>Preview</strong> on the structure gives the same numbers.</p>

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

    <section class="space-y-3">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">6. For advanced users: custom formulas</h3>
        <ul class="list-disc space-y-1.5 pl-5">
            <li>A formula gives a <strong>monthly</strong> amount. It can use the components worked out before it by their code in small letters (<code class="{{ $code }}">basic</code>, <code class="{{ $code }}">hra</code>) and the values below. The builder puts components in the right order for you.</li>
            <li>Write 40% as <code class="{{ $code }}">* 40 / 100</code> or <code class="{{ $code }}">* 0.40</code>; <code class="{{ $code }}">%</code> on its own means remainder. Text goes in quotes and ignores case: <code class="{{ $code }}">grade == "M2"</code>.</li>
            <li>Do not multiply by <code class="{{ $code }}">paid_days</code> yourself for a component that is reduced for unpaid days; payroll already does it.</li>
        </ul>
        <h4 class="font-semibold text-gray-950 dark:text-white">Pay and month values</h4>
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

        <h4 class="font-semibold text-gray-950 dark:text-white">Employee values</h4>
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

        <h4 class="font-semibold text-gray-950 dark:text-white">Operators and functions</h4>
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

        <h4 class="font-semibold text-gray-950 dark:text-white">Ready-made formulas</h4>
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
</div>
