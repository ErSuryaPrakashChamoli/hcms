<?php

namespace App\Filament\Auth;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PasswordPolicy;
use App\Http\Middleware\EnforceAccountSecurity;
use Closure;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * HROne setup parity (Security → Password policy, "reset password after N days"): the page EnforceAccountSecurity sends
 * a password session to once the tenant's password expiry has passed. The person proves the current password and
 * chooses a new one under the tenant's rules (length, mix, symbol, personal details, recent passwords), which must
 * differ from the current one. Other sessions end through the session password check; this one carries on.
 */
class ChangeExpiredPassword extends SimplePage
{
    use WithRateLimiting;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        if (! EnforceAccountSecurity::passwordExpiredFor($this->user(), session()->driver())) {
            redirect()->intended(Filament::getUrl());

            return;
        }
        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Choose a new password';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Your password has expired under your organisation’s security policy. Choose a new one to continue.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('currentPassword')->label('Current password')->password()->revealable(filament()->arePasswordsRevealable())
                    ->autocomplete('current-password')->required()->currentPassword(guard: Filament::getAuthGuard()),
                TextInput::make('password')->label('New password')->password()->revealable(filament()->arePasswordsRevealable())
                    ->autocomplete('new-password')->required()->same('passwordConfirmation')
                    ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void {
                        $user = $this->user();
                        $current = $user->getAttributes()['password'] ?? null;
                        if (is_string($current) && $current !== '' && Hash::check((string) $value, $current)) {
                            $fail('Choose a password different from your current one.');

                            return;
                        }
                        $problems = app(PasswordPolicy::class)->problemsFor($user, (string) $value);
                        if ($problems !== []) {
                            $fail(implode(' ', $problems));
                        }
                    }),
                TextInput::make('passwordConfirmation')->label('Confirm new password')->password()->revealable(filament()->arePasswordsRevealable())
                    ->autocomplete('new-password')->required(),
            ])
            ->statePath('data');
    }

    public function change(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $e) {
            Notification::make()->danger()->title("Too many attempts. Try again in {$e->secondsUntilAvailable} seconds.")->send();

            return;
        }

        $data = $this->form->getState();
        $user = $this->user();

        DB::transaction(function () use ($user, $data): void {
            $user->forceFill(['password' => (string) $data['password']])->withAuditReason('Password expired')->save();
            app(AuditRecorder::class)->record(AuditAction::PasswordChanged, 'identity', $user, [], null, tenantId: $user->tenant_id, actor: $user, metadata: ['method' => 'expired']);
        });
        // The session password check signs out every other session; this one is updated so it continues.
        session()->put(['password_hash_'.Filament::getAuthGuard() => $user->getAuthPassword()]);
        $this->data = [];

        Notification::make()->success()->title('Your password has been changed.')->send();
        $this->redirect(session()->pull('url.intended', Filament::getUrl()));
    }

    public function signOut(): void
    {
        Filament::auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirect(Filament::getLoginUrl());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('change')
                ->footer([
                    Actions::make([
                        Action::make('change')->label('Change password')->submit('change'),
                    ])->fullWidth(),
                ]),
            Actions::make([
                Action::make('signOut')->label('Sign out')->link()->color('gray')->action(fn () => $this->signOut()),
            ])->alignCenter(),
        ]);
    }

    private function user(): User
    {
        /** @var User */
        return Filament::auth()->user();
    }
}
