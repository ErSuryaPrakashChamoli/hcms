<?php

use Database\Seeders\Demo\DemoOrganisation;

require_once __DIR__.'/PopulatedSweepHelpers.php';

/*
| QA sweep over a populated organisation (the demo organisation: two dozen people, every module filled through its domain
| services). EveryPageOpensTest opens each screen of an empty tenant; this opens every screen — each page, each register's
| list and create page, and the record pages of every register's oldest and newest record — with real records in it:
| as the tenant super admin, as an employee, and as another organisation's administrator. It also works every register's
| table and submits every form. Slow (it seeds the demo organisation): skip with --exclude-group=populated-sweep.
*/

/** @return list<string> the results that are server errors */
function sweepServerErrors(array $results): array
{
    return array_values(array_map(fn (array $r) => "{$r['label']}: {$r['status']} {$r['error']}", array_filter($results, fn (array $r) => $r['status'] >= 500)));
}

beforeEach(function () {
    $this->travelTo('2026-11-11 10:00:00');
    $this->admin = seedPopulatedOrganisation();
    actAsDemoPersona($this->admin->email);
    $this->screens = populatedScreens();
});

it('opens every screen with real records, keeps an employee to their own, and keeps another organisation out', function () {
    expect(count($this->screens))->toBeGreaterThan(400);

    $admin = openScreens($this->screens);

    actAsDemoPersona('priya.nair@demo.local');
    $employee = openScreens($this->screens);
    // A screen the employee may not open never answers 200 (their own record pages and self-service pages may).
    $shown = array_values(array_map(fn ($r) => $r['label'], array_filter($employee, fn ($r) => ! $r['allowed'] && $r['status'] === 200)));

    $records = array_values(array_filter($this->screens, fn (array $screen) => $screen['record'] !== null));
    $intruderTenant = provisionTenant('Intruder');
    actAsTenant($intruderTenant);
    app('auth')->forgetGuards();
    app('session')->driver()->flush();
    $this->actingAs(tenantUser($intruderTenant, ['*']));
    $intruder = openScreens($records);
    $crossed = array_values(array_map(fn ($r) => "{$r['label']}: {$r['status']}", array_filter($intruder, fn ($r) => ! in_array($r['status'], [403, 404], true))));

    expect(sweepServerErrors($admin))->toBe([])
        ->and(sweepServerErrors($employee))->toBe([])
        ->and($shown)->toBe([])
        ->and(count($records))->toBeGreaterThan(80)
        ->and($crossed)->toBe([]);
})->group('populated-sweep');

it('searches, sorts and filters every register, answers an empty form with validation and saves an unchanged record', function () {
    actAsTenant(DemoOrganisation::tenant());
    $tables = workEveryTable();
    $forms = submitEveryForm($this->screens);

    // Create pages that hand over to their own wizard page (PolicyWizard, SalaryStructureBuilder) redirect on mount.
    $exceptions = array_filter($forms['exceptions'], fn (string $error) => ! str_contains($error, 'Invalid Livewire snapshot structure'));

    expect($tables['steps'])->toBeGreaterThan(800)
        ->and($tables['failures'])->toBe([])
        ->and($forms['submitted'])->toBeGreaterThan(100)
        ->and($exceptions)->toBe([])
        ->and($forms['unchanged_refused'])->toBe([]);
})->group('populated-sweep');
