<?php

namespace App\Filament\Auth;

use App\Filament\Support\PayPasswordGate;
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

/**
 * HROne parity MS-9 (`app-password-checker`: "Enter Password to open CTC — Password for CTC is login password of
 * System", GO TO HOME, SUBMIT): the page ConfirmPasswordForPay sends the employee to while the organisation asks for the
 * password before pay pages. The login password is checked (Filament's current-password rule, five tries a minute), the
 * session records the confirmation (Laravel's `passwordConfirmed()`, PayPasswordGate::TIMEOUT_SECONDS) and the person
 * returns to the page they asked for. With the setting off it simply returns there.
 */
class ConfirmPayPassword extends SimplePage
{
    use WithRateLimiting;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        if (! PayPasswordGate::required()) {
            redirect()->intended(Filament::getUrl());

            return;
        }
        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Enter your password';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Your pay details are protected. Enter the password you sign in to PeopleOS with to open them.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('password')->label('Password')->password()->revealable(filament()->arePasswordsRevealable())
                    ->autocomplete('current-password')->required()->currentPassword(guard: Filament::getAuthGuard())
                    ->helperText('Your login password. It is asked again after '.intdiv(PayPasswordGate::TIMEOUT_SECONDS, 60).' minutes.'),
            ])
            ->statePath('data');
    }

    public function confirm(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $e) {
            Notification::make()->danger()->title("Too many attempts. Try again in {$e->secondsUntilAvailable} seconds.")->send();

            return;
        }

        $this->form->getState();
        session()->passwordConfirmed();
        $this->data = [];

        $this->redirect(session()->pull('url.intended', Filament::getUrl()));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('confirm')
                ->footer([
                    Actions::make([
                        Action::make('confirm')->label('Open my pay details')->submit('confirm'),
                    ])->fullWidth(),
                ]),
            Actions::make([
                Action::make('home')->label('Go to Home')->link()->color('gray')->url(fn () => Filament::getUrl()),
            ])->alignCenter(),
        ]);
    }
}
