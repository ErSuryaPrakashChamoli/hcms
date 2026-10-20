<?php

namespace Database\Seeders\Production;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PasswordResets;
use App\Support\Observability\PlatformReadiness;
use Filament\Facades\Filament;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The first platform operator (PEOPLEOS_BOOTSTRAP_OPERATOR_EMAIL), created once. Production has no other way to create
 * one: operators are not tenant users, so no screen makes them.
 *
 * - No password is set or shown: the account gets a random one nobody knows, and is e-mailed a password-reset link.
 *   If the e-mail fails, the operator uses "Forgot password" on the sign-in page.
 * - MFA is enrolled at the first sign-in (platform operators cannot work without it).
 * - An existing operator with the address is left as it is. An existing tenant user with it is refused, never promoted.
 * - Production refuses a reserved, undeliverable address (.local, .test, example.*): readiness would fail on it.
 */
class PlatformOperatorSeeder extends Seeder
{
    public const REASON = 'Production bootstrap: first platform operator (ProductionSeeder)';

    public function run(PasswordResets $resets): void
    {
        $email = Str::lower(trim((string) config('peopleos.bootstrap.operator.email')));
        if ($email === '') {
            $this->command?->line('  Platform operator: skipped (set PEOPLEOS_BOOTSTRAP_OPERATOR_EMAIL to create the first one).');

            return;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('PEOPLEOS_BOOTSTRAP_OPERATOR_EMAIL is not a valid e-mail address.');
        }
        if (app()->environment('production') && PlatformReadiness::isReservedEmail($email)) {
            throw new RuntimeException('PEOPLEOS_BOOTSTRAP_OPERATOR_EMAIL uses a reserved, undeliverable domain (.local, .test, example.*); production needs a real mailbox.');
        }

        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            if (! $existing->isPlatformAdmin()) {
                throw new RuntimeException('PEOPLEOS_BOOTSTRAP_OPERATOR_EMAIL belongs to a tenant user. A tenant account is never promoted to platform operator; choose another address.');
            }
            $this->command?->line('  Platform operator: already exists, unchanged.');

            return;
        }

        $name = trim((string) config('peopleos.bootstrap.operator.name')) ?: 'Platform Operator';
        $operator = (new User)->forceFill([
            'tenant_id' => null,
            'name' => Str::limit($name, 255, ''),
            'email' => $email,
            'password' => Str::random(64),
            'is_platform_admin' => true,
            'status' => UserStatus::Active,
        ])->withAuditReason(self::REASON);
        $operator->save();

        try {
            $sent = $resets->sendNow($operator);
        } catch (Throwable $e) {
            report($e);
            $sent = false;
        }

        $this->command?->info("  Platform operator {$email} created.");
        $this->command?->line($sent
            ? '  A password-reset link was e-mailed to that address; it is valid for '.(int) config('auth.passwords.users.expire', 60).' minutes.'
            : '  The password-reset e-mail was not sent (check the mail settings).');
        $this->command?->line('  To sign in: '.Filament::getRequestPasswordResetUrl().' ("Forgot password") for a new link, then enrol MFA at the first sign-in.');
    }
}
