<?php

namespace App\Filament\Signup;

use App\Domain\Signup\Services\SignupApplications;
use App\Domain\Signup\Services\SignupCatalogue;
use App\Domain\Signup\Support\SignupRefused;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * SaaS.10: step 1 of self-service signup (guest). Switched off by default (D10-1): then only a notice is shown and
 * nothing is accepted. On: the visitor chooses a country and an offered plan (approved configuration only), gives a
 * work e-mail, their name and the organisation's name, and always gets the same answer ("check your inbox") in the
 * same time, whatever the address (SignupApplications::start). Nobody is signed in here; someone who is signed in is
 * asked to sign out first.
 */
class SignupStart extends SimplePage
{
    use WithRateLimiting;

    #[Locked]
    public bool $enabled = false;

    #[Locked]
    public bool $signedIn = false;

    #[Locked]
    public bool $sent = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected Width|string|null $maxWidth = Width::TwoExtraLarge;

    public function mount(): void
    {
        $this->signedIn = Filament::auth()->check();
        $this->enabled = app(SignupCatalogue::class)->enabled();
        $countries = $this->enabled && ! $this->signedIn ? app(SignupCatalogue::class)->countries() : [];
        $this->form->fill(['country' => array_key_exists('IN', $countries) ? 'IN' : array_key_first($countries)]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Register your organisation';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return match (true) {
            ! $this->enabled => 'Self-service registration is not open. Contact Markedge to set up PeopleOS for your organisation.',
            $this->signedIn => 'You are signed in to PeopleOS. Sign out first to register another organisation.',
            $this->sent => SignupApplications::STARTED,
            default => 'Start with your work e-mail address. We send you a link to confirm it; then you add your organisation\'s details.',
        };
    }

    public function form(Schema $schema): Schema
    {
        $catalogue = app(SignupCatalogue::class);

        return $schema->statePath('data')->components([
            Select::make('country')->label('Country of your organisation')->required()->live()->native(false)
                ->options(fn () => $this->enabled ? $catalogue->countries() : [])
                ->afterStateUpdated(fn (Set $set) => $set('plan', null)),
            Radio::make('plan')->label('Plan')->required()->live()
                ->options(fn (Get $get) => collect($this->offers($get('country')))->mapWithKeys(fn (array $o) => [$o['plan'] => $o['name']])->all())
                ->descriptions(fn (Get $get) => collect($this->offers($get('country')))->mapWithKeys(fn (array $o) => [$o['plan'] => self::describe($o)])->all()),
            Text::make('No plan is offered for self-service registration in this country yet. Contact Markedge.')
                ->visible(fn (Get $get) => filled($get('country')) && $this->offers($get('country')) === []),
            TextInput::make('email')->label('Work e-mail address')->email()->required()->maxLength(254)->autocomplete('email'),
            TextInput::make('name')->label('Your name')->required()->minLength(2)->maxLength(120)->autocomplete('name'),
            TextInput::make('company')->label('Organisation name')->required()->minLength(2)->maxLength(200)->autocomplete('organization'),
            // A honeypot: invisible and unreachable for people; a bot that fills it gets the same answer and nothing is sent.
            TextInput::make('website')->label('Leave this field empty')->maxLength(200)
                ->extraFieldWrapperAttributes(['style' => 'display:none', 'aria-hidden' => 'true'])
                ->extraInputAttributes(['tabindex' => '-1', 'autocomplete' => 'off']),
        ]);
    }

    public function start(): void
    {
        if (! $this->enabled || $this->signedIn || $this->sent) {
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
            // The same time whether the address is new, has an application or has an account (no enumeration by timing).
            app(Timebox::class)->call(fn () => app(SignupApplications::class)->start($data, (string) request()->ip()), 400_000);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ["data.{$field}" => $messages])->all());
        } catch (SignupRefused $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->sent = true;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('start')
                ->footer([Actions::make([Action::make('start')->label('Send me a confirmation link')->submit('start')])->fullWidth()])
                ->visible(fn () => $this->enabled && ! $this->signedIn && ! $this->sent),
            Text::make('Did not receive it after a few minutes? Check your spam folder, then submit the same address again to get a new link.')
                ->visible(fn () => $this->sent),
            Actions::make([Action::make('signIn')->label('Already using PeopleOS? Sign in')->link()->url(fn () => Filament::getLoginUrl())])
                ->visible(fn () => ! $this->signedIn),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function offers(?string $country): array
    {
        return $this->enabled && filled($country) ? app(SignupCatalogue::class)->available((string) $country) : [];
    }

    /** @param  array<string, mixed>  $offer */
    public static function describe(array $offer): string
    {
        $parts = [$offer['trial_days'] > 0 ? "{$offer['trial_days']}-day trial" : 'Reviewed by Markedge (no self-service trial)'];
        foreach ($offer['prices'] as $price) {
            $parts[] = "{$price['amount']} {$price['basis']}, {$price['interval']}";
        }
        $parts[] = $offer['employee_limit'] === null ? 'no employee limit' : 'up to '.number_format($offer['employee_limit']).' employees';
        if (filled($offer['description'])) {
            array_unshift($parts, (string) $offer['description']);
        }

        return implode(' · ', $parts);
    }
}
