<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Enterprise\Models\SsoConnection;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Support\Http\SafeHttp;
use App\Support\Http\UnsafeOutboundUrl;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OpenID Connect authorization-code login (§109 SSO): redirect → callback (state check) → token → userinfo →
 * match or auto-provision the user → login. Identity comes from the provider's userinfo endpoint over TLS.
 */
final class Sso
{
    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    /** @return array{url: string, state: string} */
    public function authorizationRequest(SsoConnection $connection, string $redirectUri): array
    {
        $state = Str::random(40);
        $query = http_build_query([
            'response_type' => 'code', 'client_id' => $connection->client_id, 'redirect_uri' => $redirectUri,
            'scope' => $connection->scopes, 'state' => $state, 'nonce' => Str::random(24), 'prompt' => 'select_account',
        ]);

        return ['url' => $connection->authorization_url.(str_contains($connection->authorization_url, '?') ? '&' : '?').$query, 'state' => $state];
    }

    /** Exchanges the code and returns the provider identity (sub, email, name). */
    public function identity(SsoConnection $connection, string $code, string $redirectUri): array
    {
        $token = $this->http($connection->token_url)->asForm()->timeout(15)->post($connection->token_url, [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri,
            'client_id' => $connection->client_id, 'client_secret' => $connection->client_secret,
        ]);
        if (! $token->successful() || blank($token->json('access_token'))) {
            throw new RuntimeException('The identity provider rejected the login code.');
        }

        $info = $this->http($connection->userinfo_url)->withToken($token->json('access_token'))->timeout(15)->get($connection->userinfo_url);
        if (! $info->successful()) {
            throw new RuntimeException('Could not read the user profile from the identity provider.');
        }
        $email = strtolower((string) ($info->json('email') ?? $info->json('preferred_username') ?? $info->json('upn') ?? ''));
        $sub = (string) ($info->json('sub') ?? '');
        if ($email === '' || $sub === '') {
            throw new RuntimeException('The identity provider did not return an email and subject.');
        }

        return ['sub' => $sub, 'email' => $email, 'name' => (string) ($info->json('name') ?? Str::before($email, '@'))];
    }

    /** Production readiness closure: the IdP endpoints are tenant-configured, so they pass the SSRF guard (after DNS, pinned, no redirects). */
    private function http(string $url): PendingRequest
    {
        try {
            return app(SafeHttp::class)->to($url);
        } catch (UnsafeOutboundUrl $e) {
            throw new RuntimeException('The identity provider endpoint is not an allowed destination: '.$e->getMessage());
        }
    }

    /** Finds the tenant user for this identity, provisioning one when allowed. */
    public function resolveUser(SsoConnection $connection, array $identity): User
    {
        if (! $connection->allowsEmail($identity['email'])) {
            throw new RuntimeException('This email domain is not allowed to sign in with '.$connection->name.'.');
        }

        return $this->tenants->runAs($connection->tenant, function () use ($connection, $identity) {
            $user = User::query()->where('tenant_id', $connection->tenant_id)->where(fn ($q) => $q->where(fn ($s) => $s->where('sso_connection_id', $connection->id)->where('sso_subject', $identity['sub']))->orWhere('email', $identity['email']))->first();

            if ($user === null) {
                if (! $connection->auto_provision) {
                    throw new RuntimeException('No account exists for '.$identity['email'].' and automatic provisioning is off.');
                }
                $user = User::create(['tenant_id' => $connection->tenant_id, 'name' => $identity['name'], 'email' => $identity['email'], 'password' => Str::random(40), 'status' => UserStatus::Active]);
                if ($connection->default_role_id) {
                    $user->roles()->attach($connection->default_role_id);
                }
                $this->audit->record(AuditAction::Create, 'enterprise', $user, [], 'Provisioned by SSO '.$connection->name, tenantId: $connection->tenant_id, metadata: ['sso' => $connection->slug]);
            }

            if (! $user->isActive()) {
                throw new RuntimeException('This account is not active.');
            }

            $user->forceFill(['sso_connection_id' => $connection->id, 'sso_subject' => $identity['sub']])->save();
            $connection->update(['last_login_at' => now()]);
            $this->audit->record(AuditAction::Login, 'enterprise', $user, [], null, tenantId: $connection->tenant_id, actor: $user, metadata: ['sso' => $connection->slug]);

            return $user;
        });
    }
}
