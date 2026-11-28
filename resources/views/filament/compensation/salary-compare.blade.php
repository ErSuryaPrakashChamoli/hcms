{{--
    Owner, 2026-11-27: the salary line by line (SalaryRevisions::compare). With a "before" side: every earning, deduction,
    net pay, employer contribution, CTC and the variable target before → after, with the change and its percentage; a first
    salary (or the salary in force) shows its amounts only. Rises in pay read as good news, rises in deductions do not.
--}}
@php
    $money = fn (?float $value) => $value === null ? '—' : number_format($value, 2);
    $first = (bool) ($compare['first'] ?? true);
    $caption ??= null;
    // Rises in pay read as good news (up / down); changes in deductions and employer contributions stay neutral.
    $sections = collect($compare['sections'])->map(fn (array $section) => ['rows' => collect($section['rows'])->map(function (array $row) use ($section) {
        $pay = in_array($section['key'], ['earnings', 'reimbursements', 'ctc'], true) || str_starts_with($row['label'], 'Net pay');
        $row['tone'] = $row['change'] === null || abs($row['change']) < 0.005 ? null : ($pay ? ($row['change'] > 0 ? 'up' : 'down') : 'neutral');

        return $row;
    })->all()] + $section)->all();
@endphp
<div class="pos-sal-compare" role="region" aria-label="{{ $first ? 'Salary breakup' : 'Salary before and after' }}" tabindex="0" data-salary-compare="{{ $first ? 'single' : 'both' }}">
    <table class="pos-sal-table">
        @if ($caption)<caption class="sr-only">{{ $caption }}</caption>@endif
        <thead>
            <tr>
                <th scope="col">{{ $compare['currency'] }}</th>
                @unless ($first)<th scope="col" class="is-num">Before</th>@endunless
                <th scope="col" class="is-num">{{ $first ? 'Amount' : 'After' }}</th>
                @unless ($first)
                    <th scope="col" class="is-num">Change</th>
                    <th scope="col" class="is-num">%</th>
                @endunless
            </tr>
        </thead>
        @foreach ($sections as $section)
            <tbody data-section="{{ $section['key'] }}">
                <tr class="pos-sal-group"><th scope="rowgroup" colspan="{{ $first ? 2 : 5 }}">{{ $section['title'] }}</th></tr>
                @forelse ($section['rows'] as $row)
                    <tr @class(['is-strong' => $row['strong']]) data-line="{{ $row['code'] ?? \Illuminate\Support\Str::slug($row['label']) }}">
                        <th scope="row">
                            <span class="pos-sal-name">{{ $row['label'] }}</span>
                            @if ($row['note'])<span class="pos-sal-note">{{ $row['note'] }}</span>@endif
                        </th>
                        @unless ($first)<td class="is-num pos-sal-old">{{ $money($row['before']) }}</td>@endunless
                        <td class="is-num">{{ $money($row['after']) }}</td>
                        @unless ($first)
                            <td class="is-num" @if ($row['tone']) data-tone="{{ $row['tone'] }}" @endif>{{ $row['change'] === null ? '—' : ($row['change'] > 0 ? '+' : ($row['change'] < 0 ? '−' : '')).$money(abs($row['change'])) }}</td>
                            <td class="is-num">
                                @if ($row['percent'] === null)
                                    <span class="pos-sal-pct">{{ $row['before'] !== null && $row['before'] == 0.0 && ($row['after'] ?? 0) > 0 ? 'New' : '—' }}</span>
                                @else
                                    <span class="pos-sal-pct" @if ($row['tone']) data-tone="{{ $row['tone'] }}" @endif>{{ $row['percent'] > 0 ? '+' : '' }}{{ number_format($row['percent'], 2) }}%</span>
                                @endif
                            </td>
                        @endunless
                    </tr>
                @empty
                    <tr><th scope="row" colspan="{{ $first ? 2 : 5 }}" class="pos-sal-none">None</th></tr>
                @endforelse
            </tbody>
        @endforeach
    </table>
</div>
@if (($compare['notes'] ?? []) !== [])
    <ul class="pos-sal-notes">
        @foreach ($compare['notes'] as $note)<li>{{ $note }}</li>@endforeach
    </ul>
@endif
