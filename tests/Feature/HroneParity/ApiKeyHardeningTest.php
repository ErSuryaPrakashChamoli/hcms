<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use App\Domain\Enterprise\Services\Retention;
use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Models\ApiRequestLog;
use App\Domain\Integration\Models\IntegrationLogExport;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Integration\Services\ApiRequestLogs;
use App\Domain\Integration\Services\IntegrationLogExports;
use App\Domain\Integration\Support\IpAllowlist;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\ApiReference;
use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Filament\Resources\ApiKeys\Pages\ViewApiKey;
use App\Filament\Resources\ApiKeys\RelationManagers\RequestLogsRelationManager;
use App\Filament\Resources\ApiKeys\RelationManagers\SecurityEventsRelationManager;
use App\Filament\Resources\IntegrationLogExports\IntegrationLogExportResource;
use App\Filament\Resources\IntegrationLogExports\Pages\ManageIntegrationLogExports;
use App\Filament\Support\AuditReasonField;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| HROne parity CA-11 (API key hardening) and the API-key half of SV-26: per-key IP allowlist (validated, fails closed, empty
| = any address) and rate limit (empty = the API-wide limit), the call log (no bodies, headers or query strings; tenant-owned;
| retention), Regenerate (revoke and reissue, audited with the reason), edits audited, the key's detail page (API calls,
| Security, usage), the in-app API reference, and the queued CSV export of a key's calls.
*/

beforeEach(function () {
    $this->travelTo('2026-10-29 10:00:00');
    Storage::fake(config('peopleos.documents.disk', 'local'));
    $this->tenant = provisionTenant('Alpha');
    $this->other = provisionTenant('Beta');
    $this->admin = tenantUser($this->tenant, ['*']);
    actAsTenant($this->tenant);
    $this->issued = app(ApiKeys::class)->issue('HRIS sync', ['employees.read']);
    $this->token = $this->issued['plaintext'];
    $this->key = $this->issued['key'];
    actAsTenant(null);
});

function ca11Key(object $test, array $options, array $scopes = ['employees.read']): array
{
    return app(TenantContext::class)->runAs($test->tenant, fn () => app(ApiKeys::class)->issue('Key '.uniqid(), $scopes, null, $options));
}

function ca11Get(object $test, string $token, string $ip = '127.0.0.1', string $uri = '/api/v1/employees')
{
    return $test->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders(['X-Api-Key' => $token, 'User-Agent' => 'ca11-client/1.0'])->getJson($uri);
}

function ca11Logs(object $test): Collection
{
    return app(TenantContext::class)->runAs($test->tenant, fn () => ApiRequestLog::query()->orderBy('id')->get());
}

it('keeps today\'s behaviour for a key without limits: any address, the API-wide rate limit, one bucket per key', function () {
    expect($this->key->ipAllowlist())->toBe([])->and($this->key->rateLimitPerMinute())->toBe(120)
        ->and($this->key->getAttributes()['allowed_ips'] ?? null)->toBeNull()->and($this->key->getAttributes()['rate_limit_per_minute'] ?? null)->toBeNull();

    config(['peopleos.api.rate_limit_per_minute' => 2]);
    ca11Get($this, $this->token, '198.51.100.7')->assertOk();
    ca11Get($this, $this->token, '2001:db8::5')->assertOk();
    ca11Get($this, $this->token, '203.0.113.1')->assertStatus(429); // same key, any address: one bucket, as before
});

it('validates allowlist entries and fails closed on malformed ones', function () {
    expect(IpAllowlist::normalise("203.0.113.0/24, 198.51.100.7\n2001:db8::/32;;"))->toBe(['203.0.113.0/24', '198.51.100.7', '2001:db8::/32'])
        ->and(IpAllowlist::invalid(['10.0.0.1', '10.0.0.0/8', '2001:db8::/129', '10.0.0.0/33', '10.0.*', 'example.com', '10.0.0.0/x']))->toBe(['2001:db8::/129', '10.0.0.0/33', '10.0.*', 'example.com', '10.0.0.0/x'])
        ->and(IpAllowlist::allows([], '198.51.100.7'))->toBeTrue()
        ->and(IpAllowlist::allows(['203.0.113.0/24'], '203.0.113.200'))->toBeTrue()
        ->and(IpAllowlist::allows(['203.0.113.0/24'], '203.0.114.1'))->toBeFalse()
        ->and(IpAllowlist::allows(['2001:db8::/32'], '2001:db8:1::9'))->toBeTrue()
        ->and(IpAllowlist::allows(['203.0.113.0/24'], null))->toBeFalse()
        // A malformed entry never matches anything (and never turns the list into "any address").
        ->and(IpAllowlist::allows(['not-an-ip'], '198.51.100.7'))->toBeFalse()
        ->and(IpAllowlist::allows(['not-an-ip', '198.51.100.7'], '198.51.100.7'))->toBeTrue();

    expect(fn () => ca11Key($this, ['allowed_ips' => "203.0.113.0/24\n10.0.*"]))->toThrow(RuntimeException::class, 'Not an IP address or CIDR block: 10.0.*')
        ->and(fn () => ca11Key($this, ['rate_limit_per_minute' => 0]))->toThrow(RuntimeException::class, 'rate limit')
        ->and(fn () => ca11Key($this, ['rate_limit_per_minute' => ApiKeys::MAX_RATE_LIMIT + 1]))->toThrow(RuntimeException::class, 'rate limit');
    $saved = ca11Key($this, ['allowed_ips' => '203.0.113.0/24, 2001:db8::/32', 'rate_limit_per_minute' => '30', 'description' => '  Payroll bridge  '])['key'];
    expect($saved->fresh()->allowed_ips)->toBe(['203.0.113.0/24', '2001:db8::/32'])->and($saved->fresh()->rate_limit_per_minute)->toBe(30)
        ->and($saved->fresh()->description)->toBe('Payroll bridge');
});

it('refuses a key outside its IP allowlist before scopes or tenant, and keeps a stored malformed entry closed', function () {
    $issued = ca11Key($this, ['allowed_ips' => ['203.0.113.0/24']]);
    $before = $issued['key']->fresh()->last_used_at;

    ca11Get($this, $issued['plaintext'], '198.51.100.7')->assertStatus(403)->assertJsonPath('code', 'ip_not_allowed');
    expect($issued['key']->fresh()->last_used_at)->toEqual($before);
    ca11Get($this, $issued['plaintext'], '203.0.113.9')->assertOk();

    // A malformed value that reached the row by other means narrows access; it never opens it.
    ApiKey::query()->withoutGlobalScopes()->whereKey($issued['key']->id)->update(['allowed_ips' => json_encode(['bogus'])]);
    ca11Get($this, $issued['plaintext'], '203.0.113.9')->assertStatus(403);
});

it('applies a per-key rate limit, and a key used from a refused address cannot drain its owner\'s bucket', function () {
    config(['peopleos.api.rate_limit_per_minute' => 2]);
    $limited = ca11Key($this, ['rate_limit_per_minute' => 1]);
    ca11Get($this, $limited['plaintext'])->assertOk();
    ca11Get($this, $limited['plaintext'])->assertStatus(429);
    ca11Get($this, $this->token)->assertOk(); // other keys keep their own (default) bucket

    $fenced = ca11Key($this, ['allowed_ips' => ['203.0.113.0/24'], 'rate_limit_per_minute' => 3]);
    ca11Get($this, $fenced['plaintext'], '198.51.100.50')->assertStatus(403);
    ca11Get($this, $fenced['plaintext'], '198.51.100.50')->assertStatus(403);
    ca11Get($this, $fenced['plaintext'], '198.51.100.50')->assertStatus(429); // the stranger's own IP bucket (API-wide limit)
    ca11Get($this, $fenced['plaintext'], '203.0.113.9')->assertOk();
    ca11Get($this, $fenced['plaintext'], '203.0.113.9')->assertOk();
    ca11Get($this, $fenced['plaintext'], '203.0.113.9')->assertOk();
    ca11Get($this, $fenced['plaintext'], '203.0.113.9')->assertStatus(429);

    // Refused calls (address, rate limit) are in the key's call log too.
    expect(ca11Logs($this)->where('api_key_id', $limited['key']->id)->pluck('status')->all())->toBe([200, 429])
        ->and(ca11Logs($this)->where('api_key_id', $fenced['key']->id)->pluck('status')->all())->toBe([403, 403, 429, 200, 200, 200, 429]);
});

it('logs every call made with a verified key without bodies, headers or query strings, for the key\'s tenant only', function () {
    ca11Get($this, $this->token, '198.51.100.7', '/api/v1/employees?token=sekrit-query&per_page=5')->assertOk();
    ca11Get($this, $this->token, '198.51.100.7', '/api/v1/leave/types')->assertStatus(403); // lacks the scope: logged
    ca11Get($this, 'pk_unknown.nope', '198.51.100.7')->assertStatus(401); // no verified key: nothing to log against
    $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->withHeaders(['X-Api-Key' => $this->token])
        ->postJson('/api/v1/employees', ['person' => ['first_name' => 'BodyMarker']])->assertStatus(403);

    $logs = ca11Logs($this);
    expect($logs)->toHaveCount(3);
    $first = $logs->first();
    expect($first->only(['tenant_id', 'api_key_id', 'method', 'path', 'route_name', 'status', 'ip', 'user_agent']))->toBe([
        'tenant_id' => $this->tenant->id, 'api_key_id' => $this->key->id, 'method' => 'GET', 'path' => '/api/v1/employees', 'route_name' => 'api.v1.employees.index',
        'status' => 200, 'ip' => '198.51.100.7', 'user_agent' => 'ca11-client/1.0',
    ])->and($first->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($logs->pluck('status')->all())->toBe([200, 403, 403])
        ->and($logs->last()->method)->toBe('POST');

    $secret = explode('.', $this->token)[1];
    $everything = json_encode($logs->map->getAttributes()->all());
    expect($everything)->not->toContain($secret)->and($everything)->not->toContain('sekrit-query')->and($everything)->not->toContain('BodyMarker')
        ->and(collect(Schema::getColumnListing('api_request_logs'))->sort()->values()->all())
        ->toBe(['api_key_id', 'created_at', 'duration_ms', 'id', 'ip', 'method', 'path', 'route_name', 'status', 'tenant_id', 'user_agent']);

    // Tenant-owned: another tenant (or no tenant) sees none of them.
    expect(app(TenantContext::class)->runAs($this->other, fn () => ApiRequestLog::query()->count()))->toBe(0);
    actAsTenant(null);
    expect(ApiRequestLog::query()->count())->toBe(0)
        ->and(DataInventory::tableCategories()['api_request_logs'])->toBe('api');
});

it('purges call logs past the tenant\'s retention window', function () {
    ca11Get($this, $this->token)->assertOk();
    app(TenantContext::class)->runAs($this->tenant, fn () => ApiRequestLog::query()->update(['created_at' => now()->subDays(100)]));
    ca11Get($this, $this->token)->assertOk();

    $purged = app(TenantContext::class)->runAs($this->tenant, fn () => app(Retention::class)->purge());
    expect($purged['api_request_logs'])->toBe(1)->and(ca11Logs($this))->toHaveCount(1);

    app(TenantContext::class)->runAs($this->tenant, function () {
        app(SettingsRepository::class)->set('retention.api_request_logs_days', 1, 'Keep less');
        ApiRequestLog::query()->update(['created_at' => now()->subDays(2)]);
    });
    expect(app(TenantContext::class)->runAs($this->tenant, fn () => app(Retention::class)->purge())['api_request_logs'])->toBe(1);
});

it('regenerates a key: the old one is revoked at once, the new one keeps name, scopes and limits, and the step is audited', function () {
    actAsTenant($this->tenant);
    app(ApiKeys::class)->update($this->key, ['allowed_ips' => '203.0.113.0/24', 'rate_limit_per_minute' => 50, 'description' => 'HR feed'], $this->admin, 'Fence the feed');
    $old = $this->key->fresh();
    actAsTenant(null);

    ca11Get($this, $this->token, '203.0.113.5')->assertOk();
    actAsTenant($this->tenant);
    expect(fn () => app(ApiKeys::class)->regenerate($old, $this->admin, '  '))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => app(ApiKeys::class)->regenerate($old, tenantUser($this->tenant, ['api_key.view']), 'No'))->toThrow(RuntimeException::class, 'api_key.manage');
    $new = app(ApiKeys::class)->regenerate($old, $this->admin, 'Shown in a support ticket');
    actAsTenant(null);

    expect($old->fresh()->status->value)->toBe('inactive')
        ->and($new['key']->only(['name', 'description', 'scopes', 'allowed_ips', 'rate_limit_per_minute', 'regenerated_from_id']))->toBe([
            'name' => 'HRIS sync', 'description' => 'HR feed', 'scopes' => ['employees.read'], 'allowed_ips' => ['203.0.113.0/24'], 'rate_limit_per_minute' => 50, 'regenerated_from_id' => $old->id,
        ])
        ->and($new['key']->prefix)->not->toBe($old->prefix);
    ca11Get($this, $this->token, '203.0.113.5')->assertStatus(401);
    ca11Get($this, $new['plaintext'], '203.0.113.5')->assertOk();
    actAsTenant($this->tenant);
    expect(fn () => app(ApiKeys::class)->regenerate($old->fresh(), $this->admin, 'Again'))->toThrow(RuntimeException::class, 'Only an active');

    $events = AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('entity_type', ApiKey::class)->orderBy('id')->get();
    $regenerated = $events->firstWhere('action', AuditAction::ApiKeyRegenerated);
    expect((int) $regenerated->entity_id)->toBe($new['key']->id)->and($regenerated->reason)->toBe('Shown in a support ticket')
        ->and((int) $regenerated->metadata['previous_key_id'])->toBe($old->id)->and($regenerated->metadata['previous_prefix'])->toBe($old->prefix)
        ->and($events->where('entity_id', $old->id)->firstWhere('reason', 'Regenerated: Shown in a support ticket'))->not->toBeNull()
        ->and($events->where('entity_id', $old->id)->firstWhere('reason', 'Fence the feed'))->not->toBeNull();
    // Neither the secret nor its hash ever reaches the audit trail.
    $values = AuditEventChange::query()->whereIn('audit_event_id', $events->pluck('id'))->get(['field', 'before', 'after']);
    expect($values->pluck('field')->all())->not->toContain('secret_hash')
        ->and(json_encode($values))->not->toContain(explode('.', $new['plaintext'])[1]);
    actAsTenant(null);
});

it('audits edits of scopes and limits, and refuses bad ones or a revoked key', function () {
    actAsTenant($this->tenant);
    $keys = app(ApiKeys::class);
    expect(fn () => $keys->update($this->key, ['allowed_ips' => 'nope'], $this->admin))->toThrow(RuntimeException::class, 'Not an IP address')
        ->and(fn () => $keys->update($this->key, ['scopes' => []], $this->admin))->toThrow(RuntimeException::class, 'at least one scope');
    $keys->update($this->key, ['scopes' => ['employees.read', 'leave.read'], 'rate_limit_per_minute' => ''], $this->admin, 'Leave feed added');
    $event = AuditEvent::query()->withoutTenancy()->where('entity_type', ApiKey::class)->where('entity_id', $this->key->id)->where('reason', 'Leave feed added')->sole();
    expect($event->fieldChanges->pluck('field')->all())->toBe(['scopes'])
        ->and($this->key->fresh()->scopes)->toBe(['employees.read', 'leave.read']);

    $keys->revoke($this->key->fresh(), 'Retired');
    expect(fn () => $keys->update($this->key->fresh(), ['name' => 'X'], $this->admin))->toThrow(RuntimeException::class, 'revoked');
    actAsTenant(null);
});

it('issues, edits and regenerates keys from the screens, showing a secret only once', function () {
    $this->actingAs($this->admin);
    actAsTenant($this->tenant);
    Filament::getPanel('admin')->boot();

    Livewire::test(ManageApiKeys::class)
        ->callAction('create', ['name' => 'Bad', 'scopes' => ['employees.read'], 'allowed_ips' => "203.0.113.0/24\n300.1.1.1"])
        ->assertHasActionErrors(['allowed_ips']);
    Livewire::test(ManageApiKeys::class)
        ->callAction('create', ['name' => 'Payroll bridge', 'description' => 'Bank file pickup', 'scopes' => ['payroll.read'], 'allowed_ips' => "203.0.113.0/24\n2001:db8::/32", 'rate_limit_per_minute' => 30])
        ->assertHasNoActionErrors()->assertNotified('API key issued — copy it now, it will not be shown again');
    $bridge = ApiKey::query()->where('name', 'Payroll bridge')->sole();
    expect($bridge->allowed_ips)->toBe(['203.0.113.0/24', '2001:db8::/32'])->and($bridge->rate_limit_per_minute)->toBe(30)->and($bridge->description)->toBe('Bank file pickup');

    Livewire::test(ManageApiKeys::class)->callTableAction('edit', $bridge, ['allowed_ips' => '', 'rate_limit_per_minute' => null, AuditReasonField::NAME => 'Open it up'])
        ->assertHasNoTableActionErrors();
    expect($bridge->fresh()->ipAllowlist())->toBe([])->and($bridge->fresh()->getAttributes()['rate_limit_per_minute'])->toBeNull();

    Livewire::test(ManageApiKeys::class)->callTableAction('regenerate', $bridge, ['reason' => 'Rotation day'])->assertHasNoTableActionErrors()
        ->assertNotified('API key regenerated — copy the new key now, it will not be shown again');
    expect($bridge->fresh()->status->value)->toBe('inactive')
        ->and(ApiKey::query()->where('regenerated_from_id', $bridge->id)->sole()->name)->toBe('Payroll bridge');
    actAsTenant(null);
});

it('shows the key\'s API calls, security events and usage to holders of api_key.view, read-only', function () {
    ca11Get($this, $this->token, '198.51.100.7')->assertOk();
    ca11Get($this, $this->token, '198.51.100.7', '/api/v1/leave/types')->assertStatus(403);
    actAsTenant($this->tenant);
    app(ApiKeys::class)->update($this->key, ['description' => 'Read feed'], $this->admin, 'Describe it');
    $viewer = tenantUser($this->tenant, ['api_key.view']);
    actAsTenant(null);

    $this->actingAs($viewer);
    $this->get(ApiKeyResource::getUrl('view', ['record' => $this->key]))->assertOk()->assertSee('IP addresses and rate limit')->assertSee('Any address')
        ->assertSee('120 (API-wide limit)');
    actAsTenant($this->tenant);
    Livewire::test(ViewApiKey::class, ['record' => $this->key->getRouteKey()])->assertActionHidden('regenerate')->assertActionHidden('revoke')->assertActionHidden('edit')
        ->assertActionVisible('apiReference');
    Livewire::test(RequestLogsRelationManager::class, ['ownerRecord' => $this->key, 'pageClass' => ViewApiKey::class])->assertOk()
        ->assertSee('/api/v1/employees')->assertSee('/api/v1/leave/types')
        ->filterTable('status_class', '4xx')->assertDontSee('/api/v1/employees')->assertSee('/api/v1/leave/types');
    Livewire::test(SecurityEventsRelationManager::class, ['ownerRecord' => $this->key, 'pageClass' => ViewApiKey::class])->assertOk()
        ->assertSee('Issued')->assertSee('Changed')->assertSee('Describe it');
    $daily = app(ApiRequestLogs::class)->daily($this->key);
    expect($daily['labels'])->toHaveCount(30)->and(array_sum($daily['calls']))->toBe(2)->and(array_sum($daily['errors']))->toBe(1)
        ->and(app(ApiRequestLogs::class)->summary($this->key))->toMatchArray(['calls' => 2, 'errors' => 1]);
    actAsTenant(null);

    // Without either permission: no keys, no API reference.
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(ApiKeyResource::getUrl('index'))->assertForbidden();
    $this->get(ApiReference::getUrl())->assertForbidden();
});

it('serves the API reference from the route table and lets it be downloaded', function () {
    $viewer = tenantUser($this->tenant, ['api_key.view']);
    $this->actingAs($viewer);
    $this->get(ApiReference::getUrl())->assertOk()->assertSee('/api/v1/employees')->assertSee('employees.read')->assertSee('ip_not_allowed');
    actAsTenant($this->tenant);
    Livewire::test(ApiReference::class)->set('search', 'leave/requests')->assertSee('/api/v1/leave/requests')->assertDontSee('/api/v1/employees/{employee}/lifecycle')
        ->callAction('download')->assertFileDownloaded('peopleos-openapi.json');
    actAsTenant(null);
});

it('exports a key\'s calls to CSV in the background, for the person who asked only (audited)', function () {
    ca11Get($this, $this->token, '198.51.100.7', '/api/v1/employees?per_page=1')->assertOk();
    $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->withHeaders(['X-Api-Key' => $this->token, 'User-Agent' => '=HYPERLINK("x")'])
        ->getJson('/api/v1/leave/types')->assertStatus(403);
    $this->actingAs($this->admin);
    actAsTenant($this->tenant);
    Filament::getPanel('admin')->boot();

    Livewire::test(RequestLogsRelationManager::class, ['ownerRecord' => $this->key, 'pageClass' => ViewApiKey::class])
        ->callTableAction('export', data: ['from' => '2026-10-01', 'method' => 'GET'])->assertHasNoTableActionErrors();
    $export = IntegrationLogExport::query()->sole();
    expect($export->status)->toBe('completed')->and($export->row_count)->toBe(2)->and($export->filters)->toMatchArray(['api_key_id' => $this->key->id, 'method' => 'GET'])
        ->and(str_starts_with($export->path, "tenants/{$this->tenant->id}/integrations/"))->toBeTrue();
    $csv = Storage::disk($export->disk)->get($export->path);
    expect($csv)->toContain('HRIS sync ('.$this->key->prefix.')')->and($csv)->toContain('/api/v1/employees')->and($csv)->not->toContain('per_page')
        ->and($csv)->toContain("'=HYPERLINK")->and($csv)->not->toContain(explode('.', $this->token)[1]);
    expect(AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('action', AuditAction::Export)->where('entity_id', $this->key->id)->exists())->toBeTrue();

    // Only the person who asked downloads it, and the download is audited.
    $other = tenantUser($this->tenant, ['api_key.manage']);
    expect(app(IntegrationLogExports::class)->canDownload($export, $other))->toBeFalse()
        ->and(fn () => app(IntegrationLogExports::class)->download($export, $other))->toThrow(RuntimeException::class, 'Only the person');
    Livewire::test(ManageIntegrationLogExports::class)->assertCanSeeTableRecords([$export])->callTableAction('download', $export)->assertFileDownloaded(basename($export->path));
    expect(AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('action', AuditAction::Download)->where('metadata->export_id', $export->id)->exists())->toBeTrue();
    $this->actingAs($other);
    Livewire::test(ManageIntegrationLogExports::class)->assertCanNotSeeTableRecords([$export]);

    // Exports follow the report runs' retention window (file and row).
    $this->travelTo(now()->addDays(91));
    expect(app(Retention::class)->purge()['integration_log_exports'])->toBe(1)
        ->and(Storage::disk($export->disk)->exists($export->path))->toBeFalse()->and(IntegrationLogExport::query()->count())->toBe(0);
    actAsTenant(null);
    expect(IntegrationLogExportResource::getUrl('index'))->toContain('integration-log-exports');
});
