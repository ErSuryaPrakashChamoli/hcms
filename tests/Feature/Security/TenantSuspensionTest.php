<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Services\Documents;
use App\Domain\Enterprise\Models\SsoConnection;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\SessionSecurity;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Filament\Pages\Home;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
| SaaS.2 §8–§9: what "suspended tenant" means, on every entry point, and how existing sessions end.
*/

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    $this->other = provisionTenant('Other');
    $this->operator = platformAdmin();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->employee = activeEmployee(null, ['document.own', 'task.view']);
    $this->user = $this->employee->user;
    $this->document = app(Documents::class)->store($this->employee, UploadedFile::fake()->create('pan.pdf', 10, 'application/pdf'), DocumentType::query()->where('code', 'PAN')->first());
    $this->downloadUrl = app(Documents::class)->downloadUrl($this->document);
    actAsTenant($this->other);
    $this->otherUser = tenantUser($this->other, ['employee.view']);
    actAsTenant(null);
    $this->suspensions = app(TenantSuspensions::class);
    $this->login = filament()->getPanel('admin')->getLoginUrl();
});

/** Every audit event, across all chains (audit reads are tenant-scoped and fail closed). */
function allAudit(): Builder
{
    return AuditEvent::query()->withoutTenancy();
}

/** A session as it was stamped when the user signed in. */
function stampedSession(User $user): array
{
    return [SessionSecurity::SESSION_KEY => app(SessionSecurity::class)->fingerprint($user->fresh())];
}

it('ends existing sessions of a suspended tenant, and reactivation does not bring them back', function () {
    $session = stampedSession($this->user);
    $this->withSession($session)->actingAs($this->user->fresh())->get(Home::getUrl())->assertOk();

    expect($this->suspensions->suspend($this->tenant, 'Contract ended', $this->operator))->toBeTrue();
    $this->withSession($session)->actingAs($this->user->fresh())->get(Home::getUrl())->assertRedirect($this->login);
    $this->assertGuest();

    $this->suspensions->reactivate($this->tenant, 'Contract renewed', $this->operator);
    $this->withSession($session)->actingAs($this->user->fresh())->get(Home::getUrl())->assertRedirect($this->login);
    $this->assertGuest();

    // A new sign-in after reactivation works.
    $this->withSession(stampedSession($this->user))->actingAs($this->user->fresh())->get(Home::getUrl())->assertOk();
});

it('refuses protected downloads to a suspended tenant, a suspended account and an ended session', function () {
    $session = stampedSession($this->user);
    $this->withSession($session)->actingAs($this->user->fresh())->get($this->downloadUrl)->assertOk();

    $this->suspensions->suspend($this->tenant, 'Investigation', $this->operator);
    $this->withSession($session)->actingAs($this->user->fresh())->get($this->downloadUrl)->assertRedirect($this->login);
    $this->assertGuest();
    $this->suspensions->reactivate($this->tenant, 'Cleared', $this->operator);

    // A suspended account cannot download either, even inside a live session.
    $this->user->forceFill(['status' => UserStatus::Suspended])->save();
    $this->withSession(stampedSession($this->user))->actingAs($this->user->fresh())->get($this->downloadUrl)->assertRedirect($this->login);
    $this->assertGuest();
});

it('stops a suspended tenant\'s API keys and SSO, and creates nothing on the way', function () {
    actAsTenant($this->tenant);
    $key = app(ApiKeys::class)->issue('Reader', ['employees.read'])['plaintext'];
    $connection = SsoConnection::query()->create(['name' => 'IdP', 'slug' => 'acme-idp', 'provider' => 'oidc', 'client_id' => 'c', 'client_secret' => 's',
        'authorization_url' => 'https://idp.example.com/authorize', 'token_url' => 'https://idp.example.com/token', 'userinfo_url' => 'https://idp.example.com/userinfo',
        'scopes' => 'openid email', 'status' => 'active', 'auto_provision' => true]);
    actAsTenant(null);
    $this->suspensions->suspend($this->tenant, 'Non-payment review', $this->operator);
    Http::fake();

    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/employees')->assertStatus(401);
    $this->flushHeaders();
    $this->get(route('sso.redirect', $connection->slug))->assertRedirect('/admin/login');
    $this->withSession(['sso.state' => 'st', 'sso.connection' => $connection->id])->get(route('sso.callback', $connection->slug).'?state=st&code=abc')->assertRedirect('/admin/login');

    Http::assertNothingSent();
    expect(User::query()->where('tenant_id', $this->tenant->id)->where('sso_connection_id', $connection->id)->exists())->toBeFalse();
    expect(allAudit()->where('action', 'LOGIN')->where('tenant_id', $this->tenant->id)->exists())->toBeFalse();
});

it('suspends once when two operators act at the same moment, and audits on both chains', function () {
    expect($this->suspensions->suspend($this->tenant, 'Abuse report #11', $this->operator))->toBeTrue()
        ->and($this->suspensions->suspend($this->tenant->fresh(), 'Abuse report #11 (again)', platformAdmin()))->toBeFalse();

    $events = allAudit()->where('action', 'TENANT_SUSPENDED')->get();
    expect($events)->toHaveCount(2)
        ->and($events->pluck('tenant_id')->sort()->values()->all())->toBe([null, $this->tenant->id])
        ->and($events->every(fn ($e) => $e->actor_id === $this->operator->id && $e->reason === 'Abuse report #11'))->toBeTrue()
        ->and($events->every(fn ($e) => ($e->metadata['sessions_ended'] ?? null) === true))->toBeTrue()
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Suspended);
});

it('never touches another tenant\'s sessions, keys or access', function () {
    $session = stampedSession($this->otherUser);
    $this->suspensions->suspend($this->tenant, 'Only this tenant', $this->operator);

    $this->withSession($session)->actingAs($this->otherUser->fresh())->get(Home::getUrl())->assertOk();
    expect($this->other->fresh()->getAttributes()['session_epoch'])->toBe(0);
});

it('ends one user\'s sessions everywhere without touching colleagues', function () {
    actAsTenant($this->tenant);
    $colleague = tenantUser($this->tenant, ['employee.view']);
    actAsTenant(null);
    $mine = stampedSession($this->user);
    $theirs = stampedSession($colleague);
    $this->user->forceFill(['remember_token' => 'remember-me-token'])->save();

    app(SessionSecurity::class)->revokeUser($this->user, 'Lost laptop', $this->operator);

    expect($this->user->fresh()->remember_token)->toBeNull();
    $this->withSession($mine)->actingAs($this->user->fresh())->get(Home::getUrl())->assertRedirect($this->login);
    $this->withSession($theirs)->actingAs($colleague->fresh())->get(Home::getUrl())->assertOk();
    expect(allAudit()->where('action', 'SESSIONS_REVOKED')->where('entity_id', (string) $this->user->id)->sole()->reason)->toBe('Lost laptop');
});

it('keeps platform operators working when a tenant is suspended', function () {
    $this->suspensions->suspend($this->tenant, 'Review', $this->operator);
    $this->withSession(stampedSession($this->operator))->actingAs($this->operator->fresh())->get(Home::getUrl())->assertOk();
    expect(app(TenantContext::class)->has())->toBeFalse();
});
