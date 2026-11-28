{{--
    Owner, 2026-11-28: "while giving the hike, show the existing figure and the change right there — the comparison is to
    decide the hike wisely". Under the hike (or the new amount) on Salary details (SalaryChangeWizard::impact): CTC a year,
    gross and net pay a month, in force → with this change, the change and its %. The row the change is given on stands
    out. Nothing here is saved; the Compare step keeps the line-by-line breakup.
--}}
@use('App\Filament\Support\Compensation\SalaryFormat')
@php
    $amount = fn (?float $value) => SalaryFormat::money($value, $currency, false);
    $word = $basis === 'ctc' ? 'CTC' : mb_strtolower(\App\Domain\Compensation\Services\SalaryRevisions::BASES[$basis]);
    $title = match (true) {
        $purpose === 'assign' => 'What this salary comes to',
        $purpose === 'correct' => 'The salary in force → corrected',
        default => 'The salary in force → with this change',
    };
    $showTable = $hasBefore || $hasAfter;
@endphp
<section class="pos-sal-impact" aria-live="polite" aria-label="{{ $title }}" data-salary-impact="{{ $hasAfter ? ($hasBefore ? 'both' : 'new') : 'before' }}">
    <header class="pos-sal-impact-head">
        <p class="pos-sal-impact-title">{{ $title }}</p>
        @if ($effective)<span class="pos-sal-impact-meta">From {{ $effective }}@if ($currency) · {{ $currency }}@endif</span>@endif
    </header>

    @if ($failure)
        <p class="pos-sal-impact-note" data-tone="warning" data-impact-failure>{{ $failure }}</p>
    @endif

    @if ($showTable)
        <div class="pos-sal-compare">
            <table class="pos-sal-table pos-sal-impact-table">
                <thead>
                    <tr>
                        <th scope="col"><span class="sr-only">Figure</span></th>
                        @if ($hasBefore)<th scope="col" class="is-num">In force</th>@endif
                        <th scope="col" class="is-num">{{ $hasBefore ? 'New' : 'Amount' }}</th>
                        @if ($hasBefore)<th scope="col" class="is-num">Change</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($tone = $row['change'] === null || abs($row['change']) < 0.005 ? null : ($row['change'] > 0 ? 'up' : 'down'))
                        <tr @class(['is-given' => $row['given'] && $given]) data-impact="{{ $row['key'] }}">
                            <th scope="row">
                                {{ $row['label'] }}@if ($row['key'] === 'net' && ! $complete)*@endif
                                @if ($row['given'] && $given && $hasBefore)<span class="pos-sal-impact-tag">{{ $mode === 'percent' ? 'Hike on this' : 'Set on this' }}</span>@endif
                            </th>
                            @if ($hasBefore)<td class="is-num pos-sal-old">{{ $amount($row['before']) }}</td>@endif
                            <td class="is-num is-new">{{ $amount($row['after']) }}</td>
                            @if ($hasBefore)
                                <td class="is-num" @if ($tone) data-tone="{{ $tone }}" @endif>
                                    @if ($row['change'] === null)
                                        —
                                    @else
                                        {{ SalaryFormat::signed($row['change'], $currency, false) }}
                                        @if ($row['percent'] !== null)<span class="pos-sal-pct" @if ($tone) data-tone="{{ $tone }}" @endif>{{ SalaryFormat::percent($row['percent']) }}</span>@endif
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($hint)
        <p class="pos-sal-impact-note" data-impact-hint>{{ $hint }}</p>
    @endif

    @if ($solved && $hasAfter)
        <p class="pos-sal-impact-note" data-impact-solved>
            Target {{ $word }} {{ $amount($solved['target']) }}{{ $solved['basis'] === 'ctc' ? ' a year' : ' a month' }}: the structure's formulas give
            {{ $amount($solved['achieved']) }} with an annual CTC of {{ $amount($rows[0]['after']) }}.
        </p>
    @endif

    @if ($arrears)
        @if ($arrears['enabled'])
            <p class="pos-sal-impact-note" data-impact-arrears>
                Back-dated: arrears for {{ $arrears['count'] === 1 ? $arrears['first'] : $arrears['first'].' to '.$arrears['last'] }} are paid in {{ $arrears['payout'] }} payroll@if (abs($arrears['monthly']) >= 0.5), about {{ SalaryFormat::signed($arrears['estimate'], $arrears['currency']) }} in gross pay@endif.
            </p>
        @else
            <p class="pos-sal-impact-note" data-tone="warning" data-impact-arrears>Payroll is finalised through {{ $arrears['through_label'] }}: this date will be refused when the change is executed.</p>
        @endif
    @endif

    @if ($hasAfter)
        <p class="pos-sal-impact-note">@unless ($complete)* Before statutory deductions with no verified rule. @endunless Every component, before → after, is on the next step.</p>
    @endif
</section>
