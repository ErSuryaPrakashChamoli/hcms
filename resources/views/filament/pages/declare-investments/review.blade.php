{{--
    Review step of the investment declaration, under the full list of every item entered: what payroll counts, as one
    outcome card (App\Filament\Support\Review\Outcome) — anything to read first, the tax regime, each section declared
    against what payroll counts, the rough tax estimate (the chosen regime as the key tile), the previous employer, and the
    proofs to keep ready.
--}}
@php
    $outcome = \App\Filament\Support\Review\Outcome::make('What payroll counts when you submit')->data('investment-review');

    if ($rows === []) {
        $outcome->headline('You have not declared anything.', 'That is fine if nothing applies to you: payroll then deducts tax on your full salary.');
    } else {
        $outcome->headline('You declared amounts in '.count($rows).' '.\Illuminate\Support\Str::plural('section', count($rows)).'.');
    }

    $newRegimeNote = $isNew && $rows !== [];
    if ($newRegimeNote || $warnings !== []) {
        $outcome->status('warning', 'Read before submitting');
    }
    if ($newRegimeNote) {
        $outcome->problems('warning', 'You chose the new regime', ['Under the new regime these amounts are recorded but do not reduce your tax. Go back to Start if you want the old regime.']);
    }
    if ($warnings !== []) {
        $outcome->problems('warning', 'Read before submitting', $warnings);
    }

    $outcome->fact('Tax regime', $regime);

    if ($rows !== []) {
        $outcome->table('What payroll counts', ['Section', 'You declared', 'What payroll counts'],
            array_map(fn (array $row) => [$row['title'], $row['declared'], $row['counts'].($row['over'] ? ' · above the limit' : '')], $rows),
            numeric: [1]);
    }

    if (! empty($estimate)) {
        $outcome->figures(collect($estimate)->mapWithKeys(fn (array $e) => ['Estimated tax for the year · '.$e['regime'] => $e['tax']])->all(),
            'Estimated tax for the year · '.$regime)
            ->note('A rough guide from your recent salary and these amounts; payroll works out the tax deducted each month.', 'info', 'Estimated tax:');
    }

    if ($previous) {
        $outcome->fact('Previous employer this year', 'Salary '.$previous['income'].', tax deducted '.$previous['tds']);
        if (($previous['gratuity_exempt'] ?? null) || ($previous['leave_encashment_exempt'] ?? null)) {
            $outcome->fact('Exemptions used there', implode(' · ', array_filter([
                ($previous['gratuity_exempt'] ?? null) ? 'gratuity '.$previous['gratuity_exempt'] : null,
                ($previous['leave_encashment_exempt'] ?? null) ? 'leave encashment '.$previous['leave_encashment_exempt'] : null,
            ])), ($previous['exemptions_counted'] ?? false)
                ? 'Counted against the verified tax rule’s limits; the monthly tax on your salary does not change.'
                : 'Kept for your Form 12BB; they do not change your tax until a verified tax rule carries the limits.');
        }
    }

    if ($proofs !== []) {
        $outcome->problems('pending', 'Proofs to keep ready', $proofs,
            'Payroll will ask for these later in the year. An item without a proof may stop counting, which means more tax from your salary.');
    }
@endphp
{{ $outcome }}
