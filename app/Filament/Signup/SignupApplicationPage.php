<?php

namespace App\Filament\Signup;

use App\Domain\Identity\Models\User;
use App\Domain\Signup\Enums\SignupStatus;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Signup\Services\SignupApplications;
use App\Domain\Signup\Services\SignupCatalogue;
use App\Domain\Signup\Support\SignupRefused;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * SaaS.10: the applicant's application, bound to their session by the confirmed e-mail link (never by anything the
 * browser sends: the reference comes from the session on every request). While details are pending: the company
 * details, the plan's terms, and the acknowledgements of the current Terms of Service and Privacy Notice versions. Then:
 * where it stands, what happens next, a new activation e-mail when needed, and withdrawing it before set-up starts.
 */
class SignupApplicationPage extends SimplePage
{
    use WithRateLimiting;

    public const SESSION_KEY = 'signup.application';

    #[Locked]
    public bool $enabled = false;

    #[Locked]
    public bool $signedIn = false;

    /** The document versions shown on this page: the ones the applicant accepts (refused if they changed meanwhile). */
    #[Locked]
    public ?string $termsVersion = null;

    #[Locked]
    public ?string $privacyVersion = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected Width|string|null $maxWidth = Width::TwoExtraLarge;

    public function mount(): void
    {
        $this->signedIn = Filament::auth()->check();
        $this->enabled = app(SignupCatalogue::class)->enabled();
        $application = $this->application();
        if ($application?->status === SignupStatus::DetailsPending && $this->enabled) {
            $zones = SignupApplications::timezones($application->country_code);
            $this->form->fill(['legal_name' => $application->company_name, 'display_name' => mb_substr($application->company_name, 0, 120),
                'timezone' => count($zones) === 1 ? $zones[0] : null]);
            $documents = rescue(fn () => app(SignupCatalogue::class)->documents(), null, false);
            $this->termsVersion = $documents['terms']['version'] ?? null;
            $this->privacyVersion = $documents['privacy']['version'] ?? null;
        }
    }

    /** Per request (Livewire keeps no private state between requests); reset after an action changes it. */
    private ?SignupApplication $memo = null;

    private bool $memoised = false;

    public function application(): ?SignupApplication
    {
        if (! $this->memoised) {
            $this->memo = $this->signedIn ? null : app(SignupApplications::class)->forReference(session(self::SESSION_KEY));
            $this->memoised = true;
        }

        return $this->memo;
    }

    private function forget(): void
    {
        $this->memoised = false;
        $this->memo = null;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Your registration';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $application = $this->application();

        return match (true) {
            $this->signedIn => 'You are signed in to PeopleOS. Sign out first to see a registration.',
            $application === null && ! $this->enabled => 'Self-service registration is not open. Contact Markedge to set up PeopleOS for your organisation.',
            $application === null => 'Open the link we e-mailed you to continue your registration, or start again with the same address.',
            default => self::explain($application),
        };
    }

    public static function explain(SignupApplication $application): string
    {
        return match ($application->status) {
            SignupStatus::EmailPending => 'Confirm your e-mail address with the link we sent you.',
            SignupStatus::DetailsPending => 'Add your organisation\'s details and accept the terms to finish your registration.',
            SignupStatus::ReviewPending => 'Markedge is reviewing your registration. You will receive an e-mail when it is decided.',
            SignupStatus::Approved, SignupStatus::Provisioning => 'Your registration is approved. Your organisation is being set up; this page updates by itself.',
            SignupStatus::FailedRetryable => 'Setting up your organisation is taking a little longer. It is retried automatically; you do not need to do anything.',
            SignupStatus::FailedManualReview => 'Setting up your organisation needs attention from Markedge. They have been notified and will contact you.',
            SignupStatus::ActivationPending => 'Your organisation is ready. We e-mailed you a link to choose your password; it works once.',
            SignupStatus::Active => 'Your account is active. Sign in to set up your organisation.',
            SignupStatus::Rejected => 'Markedge could not approve this registration. Contact Markedge if you think this is a mistake.',
            SignupStatus::Expired => 'This registration expired before it was completed. Start again to register.',
            SignupStatus::Cancelled => 'This registration was withdrawn. Start again to register.',
        };
    }

    public function form(Schema $schema): Schema
    {
        $application = $this->application();
        $country = $application?->country_code ?? 'IN';
        $documents = $this->enabled ? rescue(fn () => app(SignupCatalogue::class)->documents(), null, false) : null;
        $link = fn (string $label, ?array $doc) => new HtmlString('I accept the <a href="'.e($doc['url'] ?? '#').'" target="_blank" rel="noopener noreferrer" class="underline">'
            .e($label).'</a> (version '.e($doc['version'] ?? '—').').');

        return $schema->statePath('data')->components([
            TextInput::make('legal_name')->label('Legal name of the organisation')->required()->minLength(2)->maxLength(200),
            TextInput::make('display_name')->label('Name shown in PeopleOS')->required()->minLength(2)->maxLength(120),
            Select::make('timezone')->label('Time zone')->required()->native(false)->searchable()
                ->options(fn () => collect(SignupApplications::timezones($country))->mapWithKeys(fn (string $z) => [$z => $z])->all()),
            TextInput::make('intended_employees')->label('Employees you plan to manage in PeopleOS')->required()->integer()->minValue(1)
                ->maxValue((int) config('peopleos.signup.max_intended_employees', 1_000_000)),
            Checkbox::make('accept_terms')->label($link('Terms of Service', $documents['terms'] ?? null))->accepted()->required(),
            Checkbox::make('accept_privacy')->label($link('Privacy Notice', $documents['privacy'] ?? null))->accepted()->required(),
            Checkbox::make('authority')->label('I am authorised to register this organisation and to administer its PeopleOS account.')->accepted()->required(),
        ]);
    }

    public function submitDetails(): void
    {
        $application = $this->application();
        if (! $this->enabled || $application?->status !== SignupStatus::DetailsPending) {
            return;
        }
        try {
            $this->rateLimit(10);
        } catch (TooManyRequestsException $e) {
            Notification::make()->danger()->title("Too many attempts. Try again in {$e->secondsUntilAvailable} seconds.")->send();

            return;
        }
        $data = $this->form->getState();
        try {
            app(SignupApplications::class)->submit($application, [
                'legal_name' => $data['legal_name'] ?? null, 'display_name' => $data['display_name'] ?? null, 'timezone' => $data['timezone'] ?? null,
                'intended_employees' => $data['intended_employees'] ?? null, 'terms_version' => $this->termsVersion, 'privacy_version' => $this->privacyVersion,
                'authority' => ($data['authority'] ?? false) && ($data['accept_terms'] ?? false) && ($data['accept_privacy'] ?? false),
            ], (string) request()->ip());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ['data.'.($field === 'authority' ? 'authority' : $field) => $messages])->all());
        } catch (SignupRefused $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->forget();
        Notification::make()->success()->title('Registration submitted.')->send();
    }

    public function cancelApplication(): void
    {
        $application = $this->application();
        if ($application === null || ! $application->status->cancellable()) {
            return;
        }
        try {
            app(SignupApplications::class)->cancel($application, null, 'Withdrawn by the applicant');
            $this->forget();
            Notification::make()->success()->title('Your registration was withdrawn.')->send();
        } catch (SignupRefused $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function resendActivation(): void
    {
        $application = $this->application();
        if ($application?->status !== SignupStatus::ActivationPending) {
            return;
        }
        try {
            $this->rateLimit(3);
            app(SignupApplications::class)->resendActivation($application);
            Notification::make()->success()->title('A new activation e-mail is on its way. The previous link stops working.')->send();
        } catch (TooManyRequestsException $e) {
            Notification::make()->danger()->title("Too many attempts. Try again in {$e->secondsUntilAvailable} seconds.")->send();
        } catch (SignupRefused $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    /** @return array<string, mixed> what the status panel shows (no token, no address beyond its domain, no operator notes) */
    public function summary(): array
    {
        $application = $this->application();
        if ($application === null) {
            return [];
        }
        $offer = rescue(fn () => app(SignupCatalogue::class)->resolve($application->plan_code, $application->country_code), null, false);
        $owner = $application->provisioned_user_id ? User::query()->find($application->provisioned_user_id) : null;

        return [
            'application' => $application,
            'plan' => $offer['name'] ?? $application->plan_code,
            'offer' => $offer,
            'steps' => [
                ['label' => 'E-mail address confirmed', 'done' => $application->email_verified_at !== null],
                ['label' => 'Details and terms submitted', 'done' => $application->submitted_at !== null],
                ['label' => 'Organisation set up in PeopleOS', 'done' => $application->provisioned_tenant_id !== null],
                ['label' => 'Your account activated (password chosen)', 'done' => $application->status === SignupStatus::Active || $owner?->isActive()],
            ],
            'trial_until' => $application->subscription_id ? $application->provisionedTenant?->trial_ends_at?->toDateString() : null,
            'polling' => in_array($application->status, [SignupStatus::Approved, SignupStatus::Provisioning, SignupStatus::FailedRetryable], true),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.signup.application')->viewData(fn () => $this->summary())->visible(fn () => $this->application() !== null),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('submitDetails')
                ->footer([Actions::make([Action::make('submitDetails')->label('Submit my registration')->submit('submitDetails')])->fullWidth()])
                ->visible(fn () => $this->enabled && $this->application()?->status === SignupStatus::DetailsPending),
            Actions::make([
                Action::make('resendActivation')->label('E-mail me a new activation link')->action(fn () => $this->resendActivation())
                    ->visible(fn () => $this->enabled && $this->application()?->status === SignupStatus::ActivationPending),
                Action::make('signIn')->label('Sign in')->url(fn () => Filament::getLoginUrl())
                    ->visible(fn () => $this->application()?->status === SignupStatus::Active),
                Action::make('cancelApplication')->label('Withdraw this registration')->color('gray')->requiresConfirmation()
                    ->modalDescription('Your registration is cancelled and cannot be resumed. You can start a new one later.')
                    ->action(fn () => $this->cancelApplication())
                    ->visible(fn () => (bool) $this->application()?->status->cancellable() && $this->application()?->status !== SignupStatus::FailedManualReview),
                Action::make('restart')->label('Start a new registration')->link()->url(fn () => route('filament.admin.signup.start'))
                    ->visible(fn () => $this->enabled && ($this->application() === null || $this->application()->status->isTerminal()) && ! $this->signedIn),
            ]),
        ]);
    }
}
