<?php

// TEMPORARY QA probe (deleted after use): collects status, time and query counts of every screen per persona.

use App\Domain\Identity\Models\User;

require_once __DIR__.'/Admin/PopulatedSweepHelpers.php';

it('probes every screen of the populated organisation per persona', function () {
    $this->travelTo('2026-11-11 10:00:00');
    $started = microtime(true);
    $admin = seedPopulatedOrganisation();
    $report = ['seed_seconds' => round(microtime(true) - $started, 1), 'personas' => []];

    actAsDemoPersona($admin->email);
    $screens = populatedScreens();
    $report['screens'] = count($screens);

    foreach (['admin@demo.local', 'kavya.menon@demo.local', 'amit.verma@demo.local', 'priya.nair@demo.local'] as $email) {
        actAsDemoPersona($email);
        $t = microtime(true);
        $report['personas'][$email] = ['results' => openScreens($screens)];
        $report['personas'][$email]['seconds'] = round(microtime(true) - $t, 1);
    }

    // Another organisation's super admin, sent to every record of the demo organisation.
    $other = provisionTenant('Intruder');
    actAsTenant($other);
    $intruder = tenantUser($other, ['*']);
    app('auth')->forgetGuards();
    $this->actingAs($intruder);
    $records = array_values(array_filter($screens, fn ($s) => $s['record'] !== null));
    $report['personas']['intruder'] = ['results' => openScreens($records)];

    // Tables worked by the super admin.
    actAsTenant(Database\Seeders\Demo\DemoOrganisation::tenant());
    actAsDemoPersona($admin->email);
    $t = microtime(true);
    $report['tables'] = workEveryTable();
    $report['tables']['seconds'] = round(microtime(true) - $t, 1);

    $t = microtime(true);
    $report['forms'] = submitEveryForm($screens);
    $report['forms']['seconds'] = round(microtime(true) - $t, 1);

    file_put_contents(getenv('QA_PROBE_OUT') ?: sys_get_temp_dir().'/qa-probe.json', json_encode($report, JSON_PRETTY_PRINT));
    expect(true)->toBeTrue();
});
