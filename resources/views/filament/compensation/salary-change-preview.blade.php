{{--
    Owner, 2026-11-27: the Compare step of a salary change (SalaryChangeWizard::previewData). What the proposal comes to in
    a few figures (CTC, gross and net pay, each with its change), how a gross or net target was met, every line before →
    after, the read-only statutory estimate (verified rules only), the salary checks and the payment mode. Nothing here is
    saved.
--}}
@php
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2);
    $signed = fn (?float $p) => $p === null ? null : ($p > 0 ? '+' : '').number_format($p, 2).'%';
@endphp
<div class="pos-sal-preview" data-proposal-preview>
    @if ($failure)
        <x-pos.step-banner tone="blocked" :title="$failure" sub="Go back to Salary details and change the values." data-preview-failure />
    @else
        @php
            $c = $compare;
            $h = $c['headline'];
            $cur = $c['currency'];
        @endphp
        <dl class="pos-step-stats" data-preview-figures>
            <div class="pos-step-stat is-key"><dt>Annual CTC</dt><dd>{{ $cur }} {{ $money($h['ctc_annual']) }}</dd>
                @if ($h['ctc'] !== null)<span class="pos-step-delta" data-delta="ctc">{{ $signed($h['ctc']) }} on {{ $cur }} {{ $money($current?->ctcAnnual) }}</span>@endif</div>
            <div class="pos-step-stat"><dt>Gross pay a month</dt><dd>{{ $money($h['gross_monthly']) }}</dd>
                @if ($h['gross'] !== null)<span class="pos-step-delta" data-delta="gross">{{ $signed($h['gross']) }}</span>@endif</div>
            <div class="pos-step-stat"><dt>Net pay a month{{ $c['complete'] ? '' : '*' }}</dt><dd>{{ $money($h['net_monthly']) }}</dd>
                @if ($h['net'] !== null)<span class="pos-step-delta" data-delta="net">{{ $signed($h['net']) }}</span>@endif</div>
            <div class="pos-step-stat"><dt>Effective from</dt><dd>{{ \Illuminate\Support\Carbon::parse($proposal['effective_from'])->format('j M Y') }}</dd>
                <span class="pos-step-delta">{{ config('peopleos.compensation.change_types.'.$proposal['change_type'], $proposal['change_type']) }}</span></div>
        </dl>

        @if ($solved)
            <p class="pos-sal-solved" data-solved="{{ $solved['basis'] }}">
                <x-filament::icon icon="heroicon-m-calculator" class="size-4" />
                <span>Given on <strong>{{ mb_strtolower($solved['label']) }}</strong>:
                    @if ($solved['current'] !== null){{ $money($solved['current']) }} in force → @endif
                    target <strong>{{ $solved['currency'] }} {{ $money($solved['target']) }}</strong>{{ $solved['basis'] === 'ctc' ? ' a year' : ' a month' }}.
                    The structure's formulas give {{ $money($solved['achieved']) }} with an annual CTC of {{ $solved['currency'] }} {{ $money($h['ctc_annual']) }}.</span>
            </p>
        @endif

        @if ($arr = ($arrears ?? null))
            {{-- Owner, 2026-11-27: a date in a month whose payroll is already finalised. --}}
            @if ($arr['enabled'])
                <x-pos.step-banner tone="pending" icon="heroicon-o-banknotes" data-arrears-preview
                    :title="'Arrears for '.($arr['count'] === 1 ? $arr['first'] : $arr['first'].' to '.$arr['last']).' are paid in '.$arr['payout'].' payroll'"
                    :sub="'Payroll is finalised through '.$arr['through_label'].'. When this change takes effect, each closed month is worked out again on the new salary, with its own attendance and loss of pay, and the difference of every pay item is paid (or recovered) in '.$arr['payout'].'. Closed payslips stay as issued.'">
                    @if (abs($arr['monthly']) >= 0.5)
                        <p class="pos-sal-arrears-estimate">About <strong>{{ \App\Filament\Support\Compensation\SalaryFormat::signed($arr['estimate'], $arr['currency']) }}</strong>
                            in gross pay: {{ \App\Filament\Support\Compensation\SalaryFormat::signed($arr['monthly'], $arr['currency']) }} a month × {{ $arr['count'] === 1 ? 'the month' : $arr['count'].' months' }} from the effective date, before loss of pay.</p>
                    @endif
                </x-pos.step-banner>
            @else
                <x-pos.step-banner tone="warning" data-arrears-preview
                    :title="'Payroll is finalised through '.$arr['through_label'].': this date will be refused when the change is executed'"
                    sub="Arrears of back-dated salary changes are switched off (Setup → Payroll). Use a date after the closed months and pay the difference on Payroll → Adjustments, or switch them on." />
            @endif
        @endif

        @include('filament.compensation.salary-compare', ['compare' => $c, 'caption' => 'The salary before and after this change'])

        @php
            $est = $result['estimate'];
        @endphp
        <div class="pos-sal-cols">
            <section class="pos-sal-card" aria-labelledby="pp-statutory">
                <h4 id="pp-statutory" class="pos-sal-card-title">Statutory deductions (estimate for {{ $est['month'] }})</h4>
                <p class="pos-caption">From verified rules only, nothing stored. Income tax (TDS) is not estimated.</p>
                @if ($est['note'])<p class="pos-caption">{{ $est['note'] }}</p>@endif
                <ul class="pos-sal-checks" data-estimate>
                    @foreach ($est['schemes'] as $code => $scheme)
                        <li data-scheme="{{ $code }}" data-scheme-state="{{ $scheme['state'] }}" data-tone="{{ $scheme['state'] === 'no_rule' ? 'warning' : ($scheme['state'] === 'estimated' ? 'ok' : 'muted') }}">
                            <span class="pos-sal-check-label">{{ $scheme['label'] }}</span><span>{{ $scheme['text'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
            <section class="pos-sal-card" aria-labelledby="pp-checks">
                <h4 id="pp-checks" class="pos-sal-card-title">Salary checks</h4>
                <p class="pos-caption">Advisory; they never stop a proposal.</p>
                <ul class="pos-sal-checks" data-checks>
                    @foreach ($result['checks'] as $check)
                        <li data-check="{{ $check['check'] }}" data-check-state="{{ $check['state'] }}" data-tone="{{ $check['state'] === 'attention' ? 'warning' : ($check['state'] === 'ok' ? 'ok' : 'muted') }}"><span>{{ $check['text'] }}</span></li>
                    @endforeach
                </ul>
                <p class="pos-caption" data-pay-mode>Salary payment mode: {{ $result['pay_mode']['label'] }}
                    @if ($payModesUrl)· <a href="{{ $payModesUrl }}" class="pos-link" target="_blank">Payment modes</a>@endif</p>
            </section>
        </div>

        <details class="pos-sal-details">
            <summary>How each component is worked out (the structure's formulas)</summary>
            @include('filament.payroll.structure-preview', ['result' => $result['preview'], 'failure' => null])
        </details>
    @endif
</div>
