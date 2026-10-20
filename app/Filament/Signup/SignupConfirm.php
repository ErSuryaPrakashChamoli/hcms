<?php

namespace App\Filament\Signup;

use App\Domain\Signup\Services\SignupApplications;
use App\Domain\Signup\Services\SignupCatalogue;
use App\Domain\Signup\Support\SignupRefused;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * SaaS.10: the page an e-mail link opens. Opening it changes nothing (mail scanners open links); the person presses
 * Continue, which consumes the single-use link, gives the browser a new session id and binds the application to that
 * session. Every failure reads the same (SignupApplications::LINK_INVALID).
 */
class SignupConfirm extends SimplePage
{
    use WithRateLimiting;

    #[Locked]
    public string $token = '';

    #[Locked]
    public bool $valid = false;

    #[Locked]
    public bool $signedIn = false;

    #[Locked]
    public bool $enabled = false;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->signedIn = Filament::auth()->check();
        $this->enabled = app(SignupCatalogue::class)->enabled();
        $this->valid = $this->enabled && ! $this->signedIn && app(SignupApplications::class)->linkUsable($token);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Confirm your e-mail address';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return match (true) {
            ! $this->enabled => 'Self-service registration is not open. Contact Markedge to set up PeopleOS for your organisation.',
            $this->signedIn => 'You are signed in to PeopleOS. Sign out first, then open the link again.',
            ! $this->valid => SignupApplications::LINK_INVALID,
            default => 'Continue to register your organisation on PeopleOS.',
        };
    }

    public function confirm(): void
    {
        if (! $this->valid || $this->signedIn) {
            return;
        }
        try {
            $this->rateLimit((int) config('peopleos.signup.limits.confirm_per_ip_per_minute', 10));
        } catch (TooManyRequestsException $e) {
            Notification::make()->danger()->title("Too many attempts. Try again in {$e->secondsUntilAvailable} seconds.")->send();

            return;
        }
        try {
            $application = app(SignupApplications::class)->confirm($this->token);
        } catch (SignupRefused $e) {
            $this->valid = false;
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        // A new session id for the session that now holds the application (no fixation).
        session()->regenerate();
        session()->put(SignupApplicationPage::SESSION_KEY, $application->reference);
        $this->redirect(route('filament.admin.signup.application'));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Actions::make([Action::make('confirm')->label('Continue')->action(fn () => $this->confirm())])->fullWidth()
                ->visible(fn () => $this->valid && ! $this->signedIn),
            Actions::make([Action::make('restart')->label('Start again')->link()->url(fn () => route('filament.admin.signup.start'))])
                ->visible(fn () => $this->enabled && ! $this->valid && ! $this->signedIn),
        ]);
    }
}
