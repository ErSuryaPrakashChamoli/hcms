<?php

namespace App\Filament\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MultiFactor;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;

/**
 * SaaS.2: proves an authenticator in a session that was opened without the login challenge (a remember-me
 * cookie or SSO). Same codes and recovery codes, same rate limit as the login challenge.
 */
class ConfirmMultiFactorAuthentication extends SimplePage
{
    use WithRateLimiting;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $user = $this->user();
        if (! $user->hasMfaEnabled() || app(MultiFactor::class)->isVerified(session()->driver(), $user)) {
            redirect()->intended(Filament::getUrl());

            return;
        }
        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Confirm it is you';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Enter the code from your authenticator app, or one of your recovery codes.';
    }

    public function form(Schema $schema): Schema
    {
        $user = $this->user();
        $challenge = MultiFactorChallenge::make();

        return $schema
            ->components([
                ...Arr::wrap($challenge->getProviderPickerSchemaComponent($user)),
                ...$challenge->getChallengeSchemaComponents($user),
            ])
            ->statePath('data');
    }

    public function confirm(): void
    {
        $user = $this->user();
        $challenge = MultiFactorChallenge::make();

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $e) {
            Notification::make()->danger()->title("Too many attempts. Try again in {$e->secondsUntilAvailable} seconds.")->send();

            return;
        }
        if ($challenge->isRateLimited($user)) {
            Notification::make()->danger()->title('Too many attempts. Try again in '.$challenge->getRateLimiterAvailableInSeconds($user).' seconds.')->send();

            return;
        }
        $challenge->hitRateLimiter($user);

        $this->form->validate();

        app(MultiFactor::class)->markVerified(session()->driver(), $user);
        session()->regenerate();

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
                ->livewireSubmitHandler('confirm')
                ->footer([
                    Actions::make([
                        Action::make('confirm')->label('Verify')->submit('confirm'),
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
