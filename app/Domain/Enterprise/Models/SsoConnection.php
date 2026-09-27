<?php

namespace App\Domain\Enterprise\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\Role;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** An OpenID Connect / OAuth2 login connection (§109, §110): Entra, Google, Okta or any OIDC provider. */
#[Fillable(['tenant_id', 'name', 'provider', 'slug', 'client_id', 'client_secret', 'authorization_url', 'token_url', 'userinfo_url', 'scopes', 'allowed_domains', 'auto_provision', 'default_role_id', 'enforce', 'status', 'last_login_at'])]
class SsoConnection extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'provider' => 'oidc', 'scopes' => 'openid profile email'];

    protected static function booted(): void
    {
        static::saving(fn (self $c) => $c->slug = Str::slug($c->slug ?: $c->name));
    }

    protected function casts(): array
    {
        return ['client_secret' => 'encrypted', 'allowed_domains' => 'array', 'auto_provision' => 'boolean', 'enforce' => 'boolean', 'last_login_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'enterprise';
    }

    public function auditLabel(): string
    {
        return "SSO {$this->name}";
    }

    public function auditSensitiveAttributes(): array
    {
        return ['client_secret'];
    }

    public function defaultRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'default_role_id');
    }

    public function allowsEmail(string $email): bool
    {
        $domains = array_filter(array_map('strtolower', $this->allowed_domains ?? []));
        if ($domains === []) {
            return true;
        }
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return in_array($domain, $domains, true);
    }
}
