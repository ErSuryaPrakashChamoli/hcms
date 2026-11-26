{{--
    "Company values…", Review: the value that changes, for one company, from a date, as one outcome card
    (App\Filament\Support\Review\Outcome). Rows come as [label, value]; a value written "before → after" reads as a
    change, struck through on the left.
--}}
@php
    $backdated ??= null;
    $outcome = \App\Filament\Support\Review\Outcome::make('What happens when you save')->data('company-values-review')
        ->headline(($headline ?? null) ?: 'One company gets its own value; the shared record stays as it is.');
    if ($backdated === null) {
        $outcome->status('clear', 'Only this company changes');
    } elseif (! $backdated['allowed']) {
        $outcome->status('blocked', 'Too far back')->problems('blocked', 'It can start on '.$backdated['earliest'].' at the earliest',
            ['Go back to Value and choose '.$backdated['earliest'].' or a later day. Finalized payroll and closed attendance months never change.']);
    } else {
        // Back-dated: what the days between then and today become, and what never changes.
        $outcome->status('warning', 'Read before saving')->problems('warning', 'Back-dated to '.$backdated['from'], [
            'From '.$backdated['from'].', this company '.$backdated['what'].'.',
            'Attendance, leave and payroll already worked out for those days keep their results until the month is run again.',
            'Finalized payroll and closed attendance months never change: it could start on '.$backdated['earliest'].' at the earliest.',
        ], $backdated['days'].' '.($backdated['days'] === 1 ? 'day' : 'days').' before today. Only this company changes.');
    }
    foreach ($rows as [$label, $value]) {
        $parts = is_string($value) ? explode(' → ', $value, 2) : [$value];
        count($parts) === 2 ? $outcome->change($label, $parts[0], $parts[1]) : $outcome->fact($label, $value);
    }
    $outcome->note('The shared record and every other company keep their values.', 'keep', 'Kept:');
@endphp
<div class="pos-clone">{{ $outcome }}</div>
