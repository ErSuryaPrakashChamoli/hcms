<?php

use App\Filament\Pages\Home;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| UX.16 security regression: role-aware surfaces show a figure only to someone who may open the screen it
| summarises, and never a platform-wide figure (one spanning tenants) to a tenant user.
*/

it('keeps platform-wide figures off a tenant administrator\'s Home and gates each governance figure by its screen', function () {
    $tenant = provisionTenant();
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Synthetic', 'failed_at' => now()]);

    // Administers users only: the administration view, but no configuration, integration or platform screens.
    $usersOnly = tenantUser($tenant, ['user.view', 'user.assign_roles']);
    $this->actingAs($usersOnly)->get(Home::getUrl())->assertOk()->assertSee('Governance')
        ->assertDontSee('Failed jobs')->assertDontSee('background jobs failed')
        ->assertDontSee('Configuration changes this week')->assertDontSee('configuration changes await approval')
        ->assertDontSee('Integration dead letters')->assertDontSee('integration messages failed');

    // May open configuration changes: that figure appears; the platform-wide one still does not.
    $configurator = tenantUser($tenant, ['user.view', 'user.assign_roles', 'configuration.view']);
    $this->actingAs($configurator)->get(Home::getUrl())->assertOk()
        ->assertSee('Configuration changes this week')->assertDontSee('Failed jobs')->assertDontSee('background jobs failed');
});
