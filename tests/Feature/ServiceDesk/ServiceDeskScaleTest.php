<?php

use App\Domain\Integration\Services\ApiKeys;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use App\Filament\Resources\Tickets\TicketResource;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ServiceDeskTestHelpers.php';

/*
 | Phase 12 §46: the HR queue, its search and the API read a constant number of queries however many
 | requests exist (eager loading, SQL-level access filtering, pagination). Measured at 15 and 150 rows.
 */

function sdQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('keeps the HR queue, its search and the API at a constant query count as requests grow', function () {
    $this->travelTo('2026-09-21 10:00:00');
    $tenant = provisionTenant();
    actAsTenant($tenant);
    $this->actingAs(tenantUser($tenant, ['*']));
    $agent = sdAgent();
    $service = sdApprovedService('SCALE_Q', ['assignment' => ['role_id' => sdTeam($agent)->id]]);
    $employees = collect(range(1, 5))->map(fn () => activeEmployee(null, ['servicedesk.request']));
    $raise = function (int $n) use ($service, $employees) {
        foreach (range(1, $n) as $i) {
            $e = $employees[$i % 5];
            app(ServiceRequests::class)->submit($service, $e, $e->user, [], ['subject' => 'Question '.$i]);
        }
    };
    $key = app(ApiKeys::class)->issue('Scale', ['servicedesk.read']);

    $measure = function () use ($agent, $key, $tenant) {
        $this->actingAs($agent);
        $list = sdQueries(fn () => $this->get(TicketResource::getUrl('index'))->assertOk());
        $search = sdQueries(fn () => $this->get(TicketResource::getUrl('index').'?tableSearch=TKT-2026')->assertOk());
        auth()->logout();
        actAsTenant(null);
        $api = sdQueries(fn () => $this->withHeader('X-Api-Key', $key['plaintext'])->getJson('/api/v1/service-desk/requests?per_page=50')->assertOk());
        $this->flushHeaders();
        actAsTenant($tenant);

        return compact('list', 'search', 'api');
    };

    $raise(15);
    $measure(); // warm caches (permissions, settings, panel) so both measurements compare like with like
    $small = $measure();
    $this->actingAs(tenantUser($tenant, ['*']));
    $raise(135);
    $large = $measure();

    fwrite(STDERR, 'service-desk scale queries 15 rows '.json_encode($small).' / 150 rows '.json_encode($large)."\n");
    expect($large)->toBe($small);
});
