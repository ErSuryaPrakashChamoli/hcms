{{--
    Clone wizard, Review: what will be created, and what must be fixed or read first, as one outcome card
    (App\Filament\Support\Review\Outcome): the copies in one sentence, the status once, the problems, one line per copy
    and the small print as notes.
--}}
@php
    $count = count($copies);
    $outcome = \App\Filament\Support\Review\Outcome::make('What happens when you create')->data('clone-review');
    if ($error) {
        $outcome->status('blocked', 'Cannot be made')->headline('This clone cannot be made.')->problems('blocked', $error);
    } else {
        $outcome->headline($count === 1
            ? 'One independent copy of '.($source !== '' ? $source : 'this record').' is created.'
            : $count.' independent copies of '.($source !== '' ? $source : 'this record').' are created, one per company.');
        if ($blockers !== []) {
            $outcome->status('blocked', 'Cannot be created yet')
                ->problems('blocked', count($blockers) === 1 ? '1 thing to fix first' : count($blockers).' things to fix first', array_map(fn ($c) => $c->message, $blockers), 'Go back to the step named in each line.');
        } elseif ($warnings !== []) {
            $outcome->status('warning', 'Read before creating')
                ->problems('warning', count($warnings) === 1 ? '1 thing to read' : count($warnings).' things to read', array_map(fn ($c) => $c->message, $warnings), 'Nothing here stops the copy; tick below once you have read it.');
        } else {
            $outcome->status('clear', 'Ready to create');
        }
    }
    if ($copies !== []) {
        $outcome->people($count === 1 ? 'The copy' : 'The copies', $copies)
            ->note('Each copy is created inactive.', 'keep', 'Starts:')
            ->note('Nothing in the original changes, and later changes to either never reach the other.', 'keep', 'Independent:');
    }
@endphp
<div class="pos-clone">{{ $outcome }}</div>
