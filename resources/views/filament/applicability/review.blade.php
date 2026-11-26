{{--
    "Applies to…", Review: what the change does before anything is written (configuration applicability, Part 8.3), as
    one outcome card (App\Filament\Support\Review\Outcome): the record and the date in one sentence, the status once,
    where it applies now → from the date (joining in green, leaving struck through), what it also brings along, and the
    small print as notes.
--}}
@php
    $outcome = \App\Filament\Support\Review\Outcome::make('What happens when you apply')->data('applicability-review');
    if ($plan === null) {
        $outcome->status('blocked', 'Choose where it applies')
            ->headline('Go back one step and pick All, Selected or Current entity only.');
    } else {
        $from = \Illuminate\Support\Carbon::parse($plan->effectiveFrom)->format('j M Y');
        $name = fn (int $id): string => $plan->companyNames[$id] ?? "Company #{$id}";
        $future = $plan->selection->includeFuture;
        $afterNames = $plan->names($plan->after).($future ? ', and companies created later' : '');
        $count = count($plan->after);

        $outcome->headline(
            $plan->changesCompanies()
                ? $label.' will apply to '.($count === 1 && ! $future ? $plan->names($plan->after) : ($count.' '.($count === 1 ? 'company' : 'companies').($future ? ' and companies created later' : ''))).' from '.$from.($plan->backdated() ? ' (back-dated)' : '').'.'
                : $label.' keeps applying to '.$afterNames.'.',
            $plan->changesCompanies() ? null : 'The companies do not change; your choice is recorded.',
        );

        if ($plan->blockers !== []) {
            $outcome->status('blocked', 'Cannot be applied yet')
                ->problems('blocked', count($plan->blockers) === 1 ? '1 thing to fix first' : count($plan->blockers).' things to fix first', $plan->blockers, 'Nothing has been changed.');
        } else {
            $readFirst = $plan->warnings !== [] || $plan->broughtAlong() !== [] || ($plan->backdated() && $plan->changesCompanies());
            $outcome->status($readFirst ? 'warning' : 'clear', $readFirst ? 'Read before applying' : 'Ready to apply');
            // Back-dated: what the days between then and today become, and what never changes.
            if ($plan->backdated() && $plan->changesCompanies()) {
                $days = (int) \Illuminate\Support\Carbon::parse($plan->effectiveFrom)->diffInDays(today());
                $outcome->problems('warning', 'Back-dated to '.$from, array_filter([
                    $plan->added === [] ? null : 'From '.$from.', '.$plan->names($plan->added).' '.(count($plan->added) === 1 ? 'uses' : 'use').' it as if it had applied then.',
                    $plan->removed === [] ? null : 'From '.$from.', '.$plan->names($plan->removed).' no longer '.(count($plan->removed) === 1 ? 'uses' : 'use').' it.',
                    'Attendance and payroll already worked out for those days keep their results until the month is run again.',
                    'Finalized payroll and closed attendance months never change'.($plan->earliest !== null ? ': it could start on '.\Illuminate\Support\Carbon::parse($plan->earliest)->format('j M Y').' at the earliest.' : '.'),
                ]), $days.' '.($days === 1 ? 'day' : 'days').' before today.');
            }
            if ($plan->broughtAlong() !== []) {
                $outcome->problems('pending', 'Also applied with it', $plan->broughtAlong(), 'What it uses comes along to the companies joining, in the same change.');
            }
            if ($plan->warnings !== []) {
                $outcome->problems('warning', count($plan->warnings) === 1 ? '1 thing to read' : count($plan->warnings).' things to read', $plan->warnings, 'Nothing here stops the change.');
            }
        }

        // Where it applies now → from the date: every company it keeps, those joining in green, those leaving struck through.
        $now = $plan->before === null
            ? [\App\Filament\Support\Review\Outcome::chip('Every company')]
            : array_map(fn (int $id) => \App\Filament\Support\Review\Outcome::chip($name($id), in_array($id, $plan->removed, true) ? 'removed' : 'neutral'), $plan->before);
        $then = array_map(fn (int $id) => \App\Filament\Support\Review\Outcome::chip($name($id), in_array($id, $plan->added, true) ? 'added' : 'neutral'), $plan->after);
        if ($future) {
            $then[] = \App\Filament\Support\Review\Outcome::chip('Companies created later', 'info');
        }
        $outcome->compare('Applies now', $now, 'From '.$from, $then);

        $outcome->fact('Configuration', $label.($typeLabel !== '' ? ' · '.ucfirst($typeLabel) : ''))
            ->fact('Joining', \App\Filament\Support\Review\Outcome::chips(array_map($name, $plan->added), 'added'), empty: 'No company joins')
            ->fact('Leaving', \App\Filament\Support\Review\Outcome::chips(array_map($name, $plan->removed), 'removed'), empty: 'No company leaves')
            ->fact('Shared configuration', $plan->sharedBefore() || $plan->sharedAfter() ? 'Yes' : 'No',
                $plan->sharedBefore() || $plan->sharedAfter() ? 'One record used by several companies.' : 'Used by one company.')
            ->fact('Creates a copy', 'No', 'The same record. Use Clone for an independent copy.');

        $outcome->note('Everything this '.($typeLabel !== '' ? $typeLabel : 'record').' points at, and everything that points at it, for the companies above.', 'check', 'Checked:')
            ->note($plan->backdated()
                ? 'Finalized payroll and closed attendance months keep what applied to them; company values (overrides) are kept.'
                : 'Past transactions keep what applied to them; company values (overrides) are kept.', 'keep', 'Kept:');
    }
@endphp
<div class="pos-clone">{{ $outcome }}</div>
