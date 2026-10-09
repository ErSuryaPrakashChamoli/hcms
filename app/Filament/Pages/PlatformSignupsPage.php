<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Signup\Enums\SignupStatus;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Signup\Services\SignupCatalogue;
use App\Domain\Signup\Services\SignupEligibility;
use App\Domain\Signup\Services\SignupReview;
use App\Domain\Signup\Support\SignupAudit;
use App\Domain\Signup\Support\SignupRefused;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * SaaS.10: self-service signup for platform operators: whether signup could work today (switch, offers, terms) and
 * why not; applications by state; one application's details, review reasons, live eligibility, set-up attempts with a
 * safe failure summary, and its audit trail. Actions (approve, reject, hold, retry, cancel, resend the activation
 * e-mail) are refused in their service unless a platform operator gives a reason, and are audited with the operator.
 * No token, link, password or IP address is shown; the applicant's address and name only while the application needs
 * them (they are removed at activation). No impersonation.
 */
class PlatformSignupsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Signups';

    protected static ?string $title = 'Self-service signups';

    protected static ?string $slug = 'platform-signups';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.platform-signups';

    #[Url]
    public ?string $status = null;

    #[Url]
    public ?int $application = null;

    /** @var Collection<int, SignupApplication>|null */
    private ?Collection $rowsCache = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, int> status => count (one grouped query) */
    public function counts(): array
    {
        return SignupApplication::query()->select('status', DB::raw('count(*) as aggregate'))->groupBy('status')->pluck('aggregate', 'status')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** @return Collection<int, SignupApplication> the latest 50 (filtered), never their tokens */
    public function rows(): Collection
    {
        return $this->rowsCache ??= SignupApplication::query()
            ->when(SignupStatus::tryFrom((string) $this->status), fn ($q, SignupStatus $s) => $q->where('status', $s->value))
            ->latest('id')->limit(50)
            ->get(['id', 'reference', 'status', 'company_name', 'display_name', 'country_code', 'plan_code', 'review_reasons', 'attempts', 'failure_code', 'created_at', 'submitted_at', 'provisioned_tenant_id']);
    }

    public function selected(): ?SignupApplication
    {
        return $this->application === null ? null : SignupApplication::query()->with(['provisionedTenant', 'decider', 'planVersion.plan'])->find($this->application);
    }

    /** @return list<array{item: string, state: string, detail: string}> */
    public function readiness(): array
    {
        return app(SignupCatalogue::class)->readiness();
    }

    /** @return array{ok: bool, code: ?string, detail: string}|null live re-check for an application an operator may approve or retry */
    public function eligibility(SignupApplication $application): ?array
    {
        if (! in_array($application->status, [SignupStatus::ReviewPending, SignupStatus::FailedRetryable, SignupStatus::FailedManualReview], true)) {
            return null;
        }
        try {
            app(SignupEligibility::class)->assertProvisionable($application);

            return ['ok' => true, 'code' => null, 'detail' => 'Eligible now: the offer, plan version, market and price are in force and the address has no account.'];
        } catch (SignupRefused $e) {
            return ['ok' => false, 'code' => $e->reason, 'detail' => $e->getMessage()];
        }
    }

    /** @return Collection<int, AuditEvent> the application's events on the platform chain (newest first) */
    public function timeline(SignupApplication $application): Collection
    {
        return app(SignupAudit::class)->timeline($application);
    }

    public function approveAction(): Action
    {
        return $this->decision('approve', 'Approve and set up', 'success', 'The organisation is set up now, as you, and the account owner is e-mailed an activation link.',
            fn (SignupApplication $a, string $reason) => app(SignupReview::class)->approve($a, auth()->user(), $reason), 'Approved; see the set-up result below');
    }

    public function rejectAction(): Action
    {
        return $this->decision('reject', 'Reject', 'danger', 'The applicant receives a neutral e-mail; your reason stays in the audit trail.',
            fn (SignupApplication $a, string $reason) => app(SignupReview::class)->reject($a, auth()->user(), $reason), 'Application rejected');
    }

    public function holdAction(): Action
    {
        return $this->decision('hold', 'Stop automatic retries', 'gray', 'The set-up waits for an operator (retry, reject or cancel).',
            fn (SignupApplication $a, string $reason) => app(SignupReview::class)->holdForReview($a, auth()->user(), $reason), 'Held for review');
    }

    public function retryAction(): Action
    {
        return $this->decision('retry', 'Retry the set-up now', 'primary', 'Eligibility is checked again; nothing from earlier attempts was kept.',
            fn (SignupApplication $a, string $reason) => app(SignupReview::class)->retry($a, auth()->user(), $reason), 'Set-up run; see the result below');
    }

    public function cancelAction(): Action
    {
        return $this->decision('cancel', 'Cancel the application', 'gray', 'The application ends; the applicant can start a new one.',
            fn (SignupApplication $a, string $reason) => app(SignupReview::class)->cancel($a, auth()->user(), $reason), 'Application cancelled');
    }

    public function resendAction(): Action
    {
        return $this->decision('resend', 'Send a new activation e-mail', 'gray', 'The previous activation link stops working.',
            fn (SignupApplication $a, string $reason) => app(SignupReview::class)->resendActivation($a, auth()->user(), $reason), 'Activation e-mail sent');
    }

    private function decision(string $name, string $label, string $color, string $description, Closure $work, string $done): Action
    {
        return Action::make($name)->label($label)->color($color)->modalDescription($description)
            ->schema([Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)])
            ->action(function (array $data) use ($work, $done) {
                $application = $this->selected();
                if ($application === null) {
                    return;
                }
                try {
                    $work($application, (string) $data['reason']);
                    $fresh = $application->fresh();
                    $failed = in_array($fresh->status, [SignupStatus::FailedRetryable, SignupStatus::FailedManualReview], true);
                    Notification::make()->{$failed ? 'warning' : 'success'}()
                        ->title($failed ? 'The set-up failed: '.($fresh->failure_summary ?? 'see the attempts below') : $done)->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
                $this->rowsCache = null;
            });
    }
}
