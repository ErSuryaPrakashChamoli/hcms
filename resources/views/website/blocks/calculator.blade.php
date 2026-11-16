{{--
    Calculator (gratuity, CTC → in-hand, leave encashment, notice period): fields and results here, the working in
    resources/js/site.js. Estimates only; the note explains the assumptions. Nothing typed here is sent anywhere.
--}}
@php
    $kind = array_key_exists($d['kind'] ?? '', \App\Domain\Website\Support\SiteDefaults::CALCULATORS) ? $d['kind'] : 'gratuity';
    $uid = 'calc-'.$index;
    $num = fn (string $name, string $label, string $value, string $hint = '', string $step = '1') => compact('name', 'label', 'value', 'hint', 'step');
    $fields = match ($kind) {
        'gratuity' => [$num('salary', 'Last drawn monthly basic + DA (₹)', '50000'), $num('years', 'Years of service', '7'), $num('months', 'Additional months', '8', 'Months beyond full years')],
        'ctc' => [$num('ctc', 'Annual CTC (₹)', '1200000'), $num('basic_pct', 'Basic as % of CTC', '40', '', '0.5'), $num('hra_pct', 'HRA as % of basic', '50', '50% metro, 40% elsewhere', '0.5'), $num('pt', 'Professional tax a month (₹)', '200')],
        'leave_encashment' => [$num('salary', 'Monthly salary for encashment (₹)', '40000', 'Often basic + DA'), $num('days', 'Leave days to encash', '15', '', '0.5')],
        'notice' => [$num('notice_days', 'Notice period (days)', '60'), $num('waived', 'Days waived by the employer', '0'), $num('salary', 'Monthly basic for recovery (₹)', '40000')],
    };
@endphp
<section class="ps-section ps-tool" data-ps-calc="{{ $kind }}" data-ceiling="{{ (int) ($d['ceiling'] ?? 0) }}">
    <div class="ps-wrap">
        <div class="ps-section-head">
            @if (filled($d['eyebrow'] ?? null))<p class="ps-eyebrow">{{ $d['eyebrow'] }}</p>@endif
            <h1 class="ps-h2">{{ $d['heading'] ?? 'Calculator' }}</h1>
            @if (filled($d['text'] ?? null))<p class="ps-lead">{{ $d['text'] }}</p>@endif
        </div>
        <div class="ps-tool-grid">
            <form class="ps-form ps-tool-form" onsubmit="return false" aria-label="Calculator inputs">
                @if ($kind === 'gratuity')
                    <div class="ps-field is-wide">
                        <label for="{{ $uid }}-covered">Is the organisation covered by the Payment of Gratuity Act?</label>
                        <select id="{{ $uid }}-covered" data-field="covered"><option value="1">Yes (10 or more employees)</option><option value="0">No</option></select>
                    </div>
                @endif
                @if ($kind === 'notice')
                    <div class="ps-field is-wide">
                        <label for="{{ $uid }}-resigned">Resignation date</label>
                        <input id="{{ $uid }}-resigned" type="date" data-field="resigned" value="{{ now()->toDateString() }}">
                    </div>
                    <div class="ps-field is-wide">
                        <label for="{{ $uid }}-leaving">Actual last day (optional)</label>
                        <input id="{{ $uid }}-leaving" type="date" data-field="leaving">
                    </div>
                @endif
                @foreach ($fields as $f)
                    <div class="ps-field">
                        <label for="{{ $uid }}-{{ $f['name'] }}">{{ $f['label'] }}</label>
                        <input id="{{ $uid }}-{{ $f['name'] }}" type="number" inputmode="decimal" min="0" step="{{ $f['step'] }}" value="{{ $f['value'] }}" data-field="{{ $f['name'] }}">
                        @if ($f['hint'])<span class="ps-field-hint">{{ $f['hint'] }}</span>@endif
                    </div>
                @endforeach
                @if ($kind === 'ctc')
                    <div class="ps-field is-wide ps-checkline">
                        <label><input type="checkbox" data-field="pf_cap" checked> Work out PF on basic capped at ₹15,000 a month</label>
                        <label><input type="checkbox" data-field="gratuity_in_ctc" checked> Gratuity is part of CTC (4.81% of basic)</label>
                    </div>
                @endif
                @if ($kind === 'leave_encashment')
                    <div class="ps-field is-wide">
                        <label for="{{ $uid }}-divisor">Days in a month for the per-day rate</label>
                        <select id="{{ $uid }}-divisor" data-field="divisor"><option value="30">30 days</option><option value="26">26 working days</option></select>
                    </div>
                @endif
            </form>
            <div class="ps-result" data-ps-result aria-live="polite">
                <p class="ps-result-label" data-ps-result-label>Estimate</p>
                <p class="ps-result-value" data-ps-result-value>—</p>
                <dl class="ps-result-rows" data-ps-result-rows></dl>
                @if (filled($d['note'] ?? null))<p class="ps-result-note">{{ $d['note'] }}</p>@endif
            </div>
        </div>
    </div>
</section>
