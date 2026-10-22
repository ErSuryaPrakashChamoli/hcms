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
            <li>Rows are calculated <strong>top to bottom by sort order</strong>, like a spreadsheet column. A formula can use the components <strong>above</strong> it by their lowercase code (<span class="{{ $code }}">basic</span>, <span class="{{ $code }}">hra</span>), the structure’s <strong>parameters</strong>, and the variables below.</li>
            <li>Every amount is <strong>monthly and for a full month</strong>. Payroll prorates afterwards for joining, exit and loss of pay when the component has “Prorated for LOP” on, so do not multiply a prorated component by <span class="{{ $code }}">paid_days</span> yourself.</li>
            <li>A result below zero is paid as 0. Wrap a balancing component in <span class="{{ $code }}">max(0, …)</span>.</li>
            <li>Text values go in quotes and match ignoring case: <span class="{{ $code }}">grade == "M2"</span>.</li>
            <li><strong>Statutory lines are not formulas.</strong> {{ implode(', ', $statutory) }} are worked out by the compliance engine from each component’s flags (PF wages, ESI wages, taxable) and the rules in force. A formula cannot compute or override them. <span class="{{ $code }}">pf_employer</span> gives the employer-PF estimate for balancing to CTC.</li>
            <li>To change a structure, create a <strong>new version</strong>, edit the draft, check it with <strong>Preview</strong>, then <strong>submit</strong>. Another person approves it. Approved versions never change, and payroll uses the version in force on each day. Formula mistakes are caught when the draft is saved.</li>
        </ol>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Parameters</h3>
        <p>Named numbers on a structure version, e.g. <span class="{{ $code }}">basic_pct = 50</span> and <span class="{{ $code }}">min_basic = 15000</span>. Write the formula once (<span class="{{ $code }}">max(ctc_monthly * basic_pct / 100, min_basic)</span>) and give each structure, or each new version, its own numbers. Names use lowercase letters, digits and <span class="{{ $code }}">_</span>.</p>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Pay and month values</h3>
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                <thead><tr><th class="{{ $th }}">Name</th><th class="{{ $th }}">Value</th><th class="{{ $th }}">Example</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($pay as $name => [$description, $example])
                        <tr><td class="{{ $td }}"><span class="{{ $code }}">{{ $name }}</span></td><td class="{{ $td }}">{{ $description }}</td><td class="{{ $td }}"><span class="{{ $code }}">{{ $example }}</span></td></tr>
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
                        <tr><td class="{{ $td }}"><span class="{{ $code }}">{{ $name }}</span></td><td class="{{ $td }}">{{ $kind }}</td><td class="{{ $td }}">{{ $description }}</td><td class="{{ $td }}"><span class="{{ $code }}">{{ $example }}</span></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Operators and functions</h3>
        <p><span class="{{ $code }}">+ - * / %</span>, comparisons <span class="{{ $code }}">&lt; &lt;= &gt; &gt;= == !=</span>, <span class="{{ $code }}">&amp;&amp;</span> (and), <span class="{{ $code }}">||</span> (or), and parentheses. A comparison gives 1 (true) or 0 (false). A division by zero fails the formula only if its result is used, so <span class="{{ $code }}">if(paid_days &gt; 0, x / paid_days, 0)</span> is safe.</p>
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                <thead><tr><th class="{{ $th }}">Function</th><th class="{{ $th }}">Gives</th><th class="{{ $th }}">Example</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($functions as [$signature, $description, $example])
                        <tr><td class="{{ $td }} whitespace-nowrap"><span class="{{ $code }}">{{ $signature }}</span></td><td class="{{ $td }}">{{ $description }}</td><td class="{{ $td }}"><span class="{{ $code }}">{{ $example }}</span></td></tr>
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
                        <tr><td class="{{ $td }}">{{ $purpose }}</td><td class="{{ $td }}"><span class="{{ $code }}">{{ $formula }}</span></td><td class="{{ $td }}">{{ $where }}</td></tr>
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
