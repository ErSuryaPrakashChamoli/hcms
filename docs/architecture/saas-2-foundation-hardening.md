# SaaS.2 — Foundation hardening: what was built and how to use it

The full report, with the reasoning and test evidence, is `docs/saas/SaaS-2-Foundation-Hardening-Report.md`. This note is the developer's map.

## Request pipeline (panel, Livewire and protected downloads)

```
EnsureSessionIsValid → Authenticate → ResolveTenant → EnforceSecurityPolicy → EnforceAccountSecurity
```

| Middleware | Role |
|---|---|
| `EnsureSessionIsValid` | Placed before authentication through the middleware priority list in `bootstrap/app.php`. Signs out an inactive user, a tenant user of an inaccessible tenant, or a session whose epochs changed |
| `ResolveTenant` | Binds a tenant user's own tenant, or an operator's grant (`PlatformTenantAccess`), and nothing else |
| `EnforceAccountSecurity` | Enforces, in order: MFA proof in this session; MFA set-up when required; e-mail verification (not for SSO sessions). Open routes: set-up, challenge, e-mail verification, logout |

Rules for new code:
- **New web routes that serve tenant data** use the `$protected` stack in `routes/web.php`.
- **New Filament pages** get the stack automatically (persistent auth middleware).

## Services

| Service | Use it for |
|---|---|
| `Identity\Services\SessionSecurity` | `revokeUser($user, $reason, $actor)` ends a user's sessions everywhere (and clears remember-me). `revokeTenant($tenant)` does the same for a whole tenant. `stamp()` / `isCurrent()` are for the login listener and the middleware only |
| `Identity\Services\MultiFactor` | `requiredFor($user)` (tenant policy, or the platform policy for operators); `isVerified` / `markVerified` (per session); `reset($user, $reason, $actor)` for an administrator reset. Audit of set-up, removal and recovery codes happens in `User` |
| `Identity\Services\UserInvitations` | `issue($user, $by)` for an `invited` user (revokes the pending one, e-mails a new link). `accept($token, $password)`, `revoke`, `revokePendingFor`. Never create an active user with a password an administrator chose |
| `Identity\Services\PasswordResets` | `request($email)` is silent and sends after the response. `reset($email, $token, $password)` returns false for any bad link |
| `Identity\Services\PasswordPolicy` | `problemsFor($user, $password)` / `assertAcceptable()`: the tenant policy (platform minimum 12) for every password a person chooses |
| `Platform\Services\PlatformTenantAccess` | `enter($operator, $tenant, $reason, $reference, $session)`, `end(…)`, `activeTenant(…)`. The only way an operator acts inside a tenant |
| `Platform\Services\TenantSuspensions` | `suspend()` / `reactivate()` with a reason. Never update `tenants.status` directly |
| `Workflow\Services\WorkflowWebhookSigning` | `secretFor`, `reveal` (audited), `rotate` (audited), `headers` |

## Audit

| Need | How |
|---|---|
| Write to the platform chain | `AuditRecorder::record(…, platform: true)` (Markedge's own record of operator actions) |
| Audit a model without `tenant_id` in its own tenant's chain | Give the model `auditTenantId()` (`Tenant` does) |
| New actions | `INVITATION_*`, `PASSWORD_RESET_REQUESTED`, `EMAIL_VERIFIED`, `SESSIONS_REVOKED`, `PLATFORM_ACCESS_STARTED` / `ENDED`, `SIGNING_SECRET_ROTATED`; MFA changes use `MFA_CHANGED` with an `event` |

Never put a password, secret, code, recovery code or token in an audit row, a log or a queued payload.

## Configuration (`config/peopleos.php`)

| Key | Default |
|---|---|
| `security.platform_mfa_required` | `true`; production refuses `false`; phpunit sets `false`; MFA tests turn it on |
| `identity.invitation_hours` | 72 |
| `platform.tenant_access_minutes` | 60 |
| `api.rate_limit_per_minute` | 120 |
| `api.idempotency_ttl_hours` | 24 |
| `seed.password` | Development seeding only |

## Tests

| Kind | Where |
|---|---|
| Feature | `tests/Feature/Security/{MultiFactorAuthentication, IdentityLifecycle, TenantSuspension, PlatformOperatorAccess, FoundationArchitecture}Test.php`, `tests/Feature/Platform/{TenantProvisioningMetadata, ApiFoundationHardening}Test.php`, `tests/Feature/Payroll/PayrollEligibilityTest.php`, `tests/Feature/Workflow/WorkflowWebhookSigningTest.php` |
| MySQL races | `tests/MySql/IdentityConcurrencyTest.php` |

Helpers:
- `proveMfa($user)` (in `tests/Pest.php`) for tests that switch a tenant's MFA policy on and then make requests.
- A forked race that uses the password broker must resolve the broker inside the child. One resolved before the fork keeps the parent's connection object, whose reconnect drops the child's transaction.

Gotchas found on the way:
- Audit reads are tenant-scoped and fail closed; assert with `AuditEvent::query()->withoutTenancy()`.
- Inside one test the scoped `TenantContext` outlives HTTP requests; reset it (`actAsTenant(null)`) where a new request must start unbound.
- Filament's MFA challenge state lives at `data.app.*` on the challenge page (`data.multiFactor.app.*` on the login page).
