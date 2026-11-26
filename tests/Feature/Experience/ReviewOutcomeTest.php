<?php

use App\Filament\Support\Review\Outcome;
use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;

/*
| The outcome card on a wizard's Review step (App\Filament\Support\Review\Outcome, owner 2026-11-26: "beautify and arrange
| it to make it more readable"): the header says what happens and the status once, facts are ruled rows, lists are chips,
| before → after reads as a change, the small print is one-line notes; everything given as text is escaped.
*/

it('shows the outcome, status, facts, chips, changes, people and notes in one card', function () {
    $html = Outcome::make('What happens when you apply')->data('applicability-review')
        ->headline('Consulting (CONS) will apply to 2 companies from 26 Nov 2026.')
        ->status('warning', 'Read before applying')
        ->problems('warning', '1 thing to read', ['Cost centre "CC-01" does not apply to Demo Retail.'])
        ->compare('Applies now', [Outcome::chip('Demo Services')], 'From 26 Nov 2026', [Outcome::chip('Demo Services'), Outcome::chip('Demo Technologies', 'added')])
        ->fact('Leaving', [], empty: 'No company leaves')
        ->change('Annual CTC', 'INR 4,20,000.00', 'INR 4,62,000.00')
        ->people('3 employees', ['EMP001 · Asha Rao', ['name' => 'EMP002 · Amit Verma', 'before' => 'Ravi', 'after' => 'Meera']], 1)
        ->note('Past transactions keep what applied to them.', 'keep', 'Kept:')
        ->toHtml();

    expect($html)
        ->toContain('class="pos-outcome"', 'data-tone="warning"', 'data-applicability-review')
        ->toContain('What happens when you apply', 'Consulting (CONS) will apply to 2 companies from 26 Nov 2026.', 'Read before applying')
        ->toContain('pos-outcome-alert', '1 thing to read', 'Cost centre &quot;CC-01&quot; does not apply to Demo Retail.')
        ->toContain('pos-outcome-compare', 'data-tone="added"', 'Demo Technologies')
        ->toContain('No company leaves', 'pos-outcome-change', 'INR 4,20,000.00', 'INR 4,62,000.00')
        ->toContain('3 employees', 'EMP001 · Asha Rao', 'and 1 more')
        ->toContain('data-kind="keep"', 'Kept:', 'Past transactions keep what applied to them.');
});

it('escapes text and shows only Htmlable values as HTML', function () {
    $html = Outcome::make()->headline('<script>alert(1)</script>')->fact('Name', '<b>Bold</b>')
        ->fact('Route', new HtmlString('<em>Manager</em>'))->note('<i>note</i>')->toHtml();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;', '&lt;b&gt;Bold&lt;/b&gt;', '<em>Manager</em>', '&lt;i&gt;note&lt;/i&gt;');
});

it('reads empty values as None and leaves out the status pill unless one is given', function () {
    $html = Outcome::make()->fact('Company', null)->fact('Number', '—')->fact('Active', true)->toHtml();

    expect($html)->toContain('is-empty', 'None', 'Yes')
        ->and($html)->not->toContain('pos-outcome-status');
});

it('is shown as it is by a Filament Text on a Review step', function () {
    $text = Text::make(fn () => Outcome::make('What happens when you change')->headline('Present on 3 days.'));

    expect($text->getContent())->toBeInstanceOf(Outcome::class)
        ->and((string) $text->getContent())->toContain('pos-outcome', 'Present on 3 days.');
});
