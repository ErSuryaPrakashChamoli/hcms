<?php

namespace App\Filament\Auth;

use App\Domain\Identity\Services\InvalidInvitation;
use App\Domain\Identity\Services\UserInvitations;
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
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * SaaS.2: the invitee opens the one-time link, chooses a password (tenant policy) and is sent to sign in,
 * where MFA set-up follows if their organisation requires it. Nobody is signed in by this page, and someone
 * already signed in is asked to sign out first (an invitation is never attached to another account).
 */
class AcceptInvitation extends SimplePage
{
    use WithRateLimiting;

    #[Locked]
    public string $token = '';

    #[Locked]
    public bool $valid = false;

    #[Locked]
    public ?string $email = null;

    #[Locked]
    public bool $signedIn = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->signedIn = Filament::auth()->check();
        $invitation = $this->signedIn ? null : app(UserInvitations::class)->pending($token);
        $this->valid = $invitation !== null;
        $this->email = $invitation?->user?->email;
        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Accept your invitation';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return match (true) {
            $this->signedIn => 'You are signed in to PeopleOS. Sign out, then open the invitation link again.',
            ! $this->valid => UserInvitations::INVALID,
            default => "Choose a password for {$this->email}.",
        };
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('password')->label('Password')->password()->revealable()->required()->rule(PasswordRule::default())
                ->same('passwordConfirmation')->autocomplete('new-password'),
            TextInput::make('passwordConfirmation')->label('Confirm password')->password()->revealable()->required()->dehydrated(false)->autocomplete('new-password'),
        ]);
    }

    public function accept(): void
    {
        if (! $this->valid || $this->signedIn) {
            return;
        }
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $e) {
            Notification::make()->danger()->title("Too many attempts. Try again in {$e->secondsUntilAvailable} seconds.")->send();

            return;
        }

        $data = $this->form->getState();
        try {
            app(UserInvitations::class)->accept($this->token, (string) $data['password']);
        } catch (InvalidInvitation $e) {
            $this->valid = false;
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['data.password' => $e->errors()['password'] ?? array_merge(...array_values($e->errors()))]);
        }

        Notification::make()->success()->title('Your account is ready. Sign in with your new password.')->send();
        $this->redirect(Filament::getLoginUrl());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('accept')
                ->footer([Actions::make([Action::make('accept')->label('Activate my account')->submit('accept')])->fullWidth()])
                ->visible(fn () => $this->valid && ! $this->signedIn),
            Text::make('Ask your administrator to send a new invitation.')->visible(fn () => ! $this->valid && ! $this->signedIn),
        ]);
    }
}
