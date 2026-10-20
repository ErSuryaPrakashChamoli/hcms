<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;

final class RecordAuthenticationEvents
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            PasswordReset::class => 'onPasswordReset',
            Verified::class => 'onVerified',
        ];
    }

    public function onLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $user->forceFill(['last_login_at' => now()])->withoutAuditing()->saveQuietly();

        $this->recorder->record(
            action: AuditAction::Login,
            module: 'identity',
            entity: $user,
            metadata: ['guard' => $event->guard, 'remember' => $event->remember],
            tenantId: $user->tenant_id,
            actor: $user,
        );
    }

    public function onLogout(Logout $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->recorder->record(
            action: AuditAction::Logout,
            module: 'identity',
            entity: $user,
            metadata: ['guard' => $event->guard],
            tenantId: $user->tenant_id,
            actor: $user,
        );
    }

    public function onFailed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        $this->recorder->record(
            action: AuditAction::LoginFailed,
            module: 'identity',
            entity: $user,
            metadata: [
                'guard' => $event->guard,
                'email' => $event->credentials['email'] ?? null,
            ],
            entityLabel: $event->credentials['email'] ?? null,
            tenantId: $user?->tenant_id,
            actor: null,
        );
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->recorder->record(
            action: AuditAction::PasswordChanged,
            module: 'identity',
            entity: $user,
            metadata: ['method' => 'reset_link'],
            tenantId: $user->tenant_id,
            actor: $user,
        );
    }

    /** SaaS.2: the owner proved a (new) e-mail address through the signed verification link. */
    public function onVerified(Verified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->recorder->record(
            action: AuditAction::EmailVerified,
            module: 'identity',
            entity: $user,
            tenantId: $user->tenant_id,
            actor: $user,
        );
    }
}
