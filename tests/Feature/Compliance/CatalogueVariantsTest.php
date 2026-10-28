<?php

use App\Domain\ComplianceReports\Support\ReportCatalogue;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\Organisation\Models\Establishment;

/** An unsaved establishment: resolveFor() reads only its state and appropriate governments. */
function variantEstablishment(?string $state, ?string $wages, ?string $socialSecurity): Establishment
{
    return new Establishment(['state' => $state, 'appropriate_government' => $wages, 'ss_appropriate_government' => $socialSecurity]);
}

it('reads the appropriate government of the Code the obligation falls under', function () {
    $catalogue = app(ReportCatalogue::class);
    // State for wages and OSH, Central for social security (a multi-state establishment, SS Code s.2(3)(a)).
    $split = variantEstablishment('KA', 'state', 'central');
    $june = ReportPeriod::month(2026, 6);

    expect($catalogue->find('maternity_register')->code)->toBe('ss')
        ->and($catalogue->find('maternity_register')->resolveFor($split, $june)->form)->toBe('Form XXII')
        ->and($catalogue->find('employee_register')->resolveFor($split, $june)->form)->toStartWith('Register of employees — PeopleOS layout');

    // Social security government not set: configuration required, even though the wages one is.
    $unset = $catalogue->find('maternity_register')->resolveFor(variantEstablishment('KA', 'state', null), $june);
    expect($unset->configurationRequired)->not->toBeNull()
        ->and($catalogue->find('employee_register')->resolveFor(variantEstablishment('KA', 'state', null), $june)->configurationRequired)->toBeNull();
});

it('applies a state\'s own forms only from its final rules under the Code, and the earlier rules before', function () {
    $register = app(ReportCatalogue::class)->find('employee_register');
    $rajasthan = variantEstablishment('RJ', 'state', 'state');

    // Rajasthan Code on Wages Rules, 2026 (S.O. 40, 12 Aug 2026): Form IV is the employee register.
    $before = $register->resolveFor($rajasthan, ReportPeriod::month(2026, 7));
    $after = $register->resolveFor($rajasthan, ReportPeriod::month(2026, 9));
    expect($before->form)->toStartWith('Register of employees — PeopleOS layout')
        ->and($after->form)->toBe('Form IV')
        ->and($after->legalBasis)->toContain('Rajasthan Code on Wages Rules, 2026')
        ->and($after->effectiveFrom)->toBe('2026-08-12')
        ->and($after->legalStatus())->toBe('LEGAL_SOURCE_VERIFIED');

    // Uttar Pradesh: Form II, read from a gazette print on a non-government host, so only partly verified.
    $up = $register->resolveFor(variantEstablishment('UP', 'state', 'state'), ReportPeriod::month(2026, 9));
    expect($up->form)->toBe('Form II')->and($up->confidence)->toBe('OFFICIAL_SOURCE_PARTIALLY_VERIFIED')->and($up->legalStatus())->toBe('LEGAL_REVIEW_REQUIRED');

    // Maharashtra has draft rules only: the generic state variant, under legal review.
    $mh = $register->resolveFor(variantEstablishment('MH', 'state', 'state'), ReportPeriod::month(2026, 9));
    expect($mh->form)->toStartWith('Register of employees — PeopleOS layout')->and($mh->legalStatus())->toBe('LEGAL_REVIEW_REQUIRED');

    // A central-sphere establishment in Rajasthan keeps the central form.
    expect($register->resolveFor(variantEstablishment('RJ', 'central', 'central'), ReportPeriod::month(2026, 9))->form)->toBe('Form I (= OSH Form XIII)');
});

it('never labels a gazette print on a non-government host as primary-verified', function () {
    $catalogue = app(ReportCatalogue::class);
    foreach ($catalogue->all() as $definition) {
        foreach ([['confidence' => $definition->confidence, 'sources' => $definition->sources], ...$definition->variants] as $entry) {
            if (($entry['confidence'] ?? null) !== 'OFFICIAL_PRIMARY_SOURCE_VERIFIED') {
                continue;
            }
            $hosts = collect($entry['sources'] ?? [])->map(fn ($s) => (string) parse_url($s[1] ?? '', PHP_URL_HOST));
            $official = $hosts->contains(fn ($h) => preg_match('/(\.gov\.in|\.nic\.in)$/', $h));
            // The one exception: the OSH (Central) Rules copy whose Government of India Press signature was validated.
            $signed = collect($entry['sources'] ?? [])->contains(fn ($s) => str_contains($s[0] ?? '', 'valid Government of India Press signature'));
            expect($official || $signed)->toBeTrue("{$definition->key}: primary-verified without an official or signed source");
        }
    }
});
