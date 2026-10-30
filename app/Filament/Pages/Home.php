<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\MoodCheckIn;
use App\Domain\Engagement\Services\MoodCheckIns;
use App\Domain\Experience\Services\ContextualIntelligence;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\HomeComposer;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Experience\Services\UxMetrics;
use App\Domain\People\Services\Birthdays;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Recognition\Models\WallPost;
use App\Domain\Recognition\Services\SocialWall;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Support\AttendanceActions;
use App\Filament\Support\BirthdayActions;
use App\Filament\Support\LeaveActions;
use App\Filament\Support\PendingRequestsPanel;
use App\Filament\Support\RequestChrome;
use App\Filament\Support\ServiceDeskActions;
use App\Filament\Support\WebPunch;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use RuntimeException;

/**
 * UX: the role-aware Home (§12). "What matters to me now": a ranked focus list with the reason each
 * item matters, the actions this person most often starts, their day (employee), their team
 * (manager), people operations (HR), the workforce pulse (executive), payroll (payroll) or platform
 * health (admin), and "What changed". The leave, attendance and HR-request forms are the existing
 * Filament actions and domain services, mounted here so that starting them is one click.
 */
class Home extends Dashboard
{
    protected static ?string $title = 'Home';

    protected static ?string $navigationLabel = 'Home';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected string $view = 'filament.pages.home';

    public function mount(): void
    {
        app(UxMetrics::class)->record('home.view');
    }

    public function getTitle(): string
    {
        if (! app(TenantContext::class)->has()) {
            return 'Platform';
        }
        $hour = (int) now()->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $first = $this->home['employee']?->person?->first_name ?? strtok((string) auth()->user()->name, ' ');

        return "{$greeting}, {$first}";
    }

    public function getSubheading(): ?string
    {
        if (! app(TenantContext::class)->has()) {
            return 'Choose a tenant to work in, or check platform readiness.';
        }

        return 'Here’s what matters today, '.now()->format('j F Y');
    }

    /** The hero inside the page carries the greeting; the default header is not rendered (outside a tenant it is). */
    public function getHeader(): ?View
    {
        return app(TenantContext::class)->has() ? view('filament.shell.empty') : null;
    }

    public function getWidgets(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function home(): array
    {
        if (! app(TenantContext::class)->has()) {
            return ['employee' => null, 'focus' => ['summary' => '', 'items' => [], 'counts' => []], 'lens' => RoleLens::SYSTEM_ADMIN, 'lenses' => []];
        }

        return app(HomeComposer::class)->for(auth()->user());
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function platform(): array
    {
        return [
            'tenants' => Tenant::query()->orderBy('name')->limit(12)->get(),
            'tenants_url' => TenantResource::canAccess() ? TenantResource::getUrl('index') : null,
            'readiness_url' => PlatformReadinessPage::canAccess() ? PlatformReadinessPage::getUrl() : null,
        ];
    }

    /** UX.15: PeopleOS Intelligence for Home (contextual, sourced, gated by the AI policy). */
    #[Computed]
    public function intelligence(): ?array
    {
        return app(TenantContext::class)->has() ? app(ContextualIntelligence::class)->forHome(auth()->user(), $this->home) : null;
    }

    /** UX.15: the brief of the day as one sentence; numbers emphasised, every part escaped. */
    public function briefHtml(): string
    {
        $parts = array_map(fn (array $p) => '<b class="pos-num">'.e((string) $p[0]).'</b> '.e($p[1]), $this->home['brief'] ?? []);
        // UX.16: executives start from the workforce headline (real movement this month against last).
        $lead = $this->home['brief_text'] ?? null;
        if ($parts === []) {
            return $lead !== null ? e($lead) : e('You’re all caught up. Nothing needs you right now.');
        }
        $text = count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)).' and '.end($parts) : $parts[0];

        return $lead !== null ? e($lead).' '.e('Also,').' '.$text.'.' : e('Here’s what matters today:').' '.$text.'.';
    }

    /** Switch the home lens (persisted; only lenses the person actually has). */
    public function switchLens(string $lens): void
    {
        if (! in_array($lens, app(RoleLens::class)->lenses(auth()->user()), true)) {
            return;
        }
        app(ExperiencePreferences::class)->update(auth()->user(), ['lens' => $lens]);
        unset($this->home);
        // UX.17: the phone bar follows the new view at once.
        $this->dispatch('pos-experience-changed');
    }

    /**
     * First-login welcome (§47): shown once, until the person dismisses it. HROne setup parity HP-9a: the organisation
     * may switch it off (experience.welcome.enabled).
     */
    public function showWelcome(): bool
    {
        return app(TenantContext::class)->has() && (bool) app(SettingsRepository::class)->get('experience.welcome.enabled', true)
            && (app(ExperiencePreferences::class)->for(auth()->user())['welcomed_at'] ?? null) === null;
    }

    /** HP-9a: the organisation's own welcome ({name}, {organisation}), or null for PeopleOS's built-in text. */
    public function welcomeMessage(): ?string
    {
        $message = app(SettingsRepository::class)->get('experience.welcome.message');
        if (blank($message)) {
            return null;
        }
        $first = $this->home['employee']?->person?->first_name ?? strtok((string) auth()->user()->name, ' ');

        return app(Birthdays::class)->render((string) $message, (string) $first);
    }

    public function dismissWelcome(): void
    {
        app(ExperiencePreferences::class)->update(auth()->user(), ['welcomed_at' => now()->toIso8601String()]);
    }

    /** "Not now": hide a next-best-action card until tomorrow (the item itself is unchanged). */
    public function notNow(string $key): void
    {
        app(ExperiencePreferences::class)->snooze(auth()->user(), 'home:'.mb_substr($key, 0, 120), now()->addDay()->startOfDay()->toIso8601String());
        unset($this->home);
    }

    public function hideCard(string $card): void
    {
        $prefs = app(ExperiencePreferences::class)->for(auth()->user());
        $hidden = array_values(array_unique([...($prefs['home_hidden'] ?? []), $card]));
        app(ExperiencePreferences::class)->update(auth()->user(), ['home_hidden' => $hidden]);
        unset($this->home);
    }

    /** @param  array<string, mixed>  $client  what the browser sends (WebPunch) */
    public function punch(string $direction, array $client = []): void
    {
        $employee = $this->home['employee'];
        if ($employee === null || ! in_array($direction, ['in', 'out'], true)) {
            return;
        }
        try {
            if (WebPunch::record($employee, $direction, $client)) {
                $this->dispatch('pos-success', message: $direction === 'in' ? 'Checked in' : 'Checked out');
            }
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Could not record')->body($e->getMessage())->send();
        }
        unset($this->home);
    }

    public function requestLeaveAction(): Action
    {
        return Action::make('requestLeave')->label('Request leave')->icon(Heroicon::OutlinedCalendarDays)
            ->modalHeading('Request leave')
            ->modalDescription('Your balance updates as you pick dates. Your manager is notified when you submit.')
            ->modalSubmitActionLabel('Submit request')
            ->visible(fn () => $this->home['employee'] !== null && auth()->user()->can('leave.apply'))
            ->schema(LeaveActions::applyForm(fn () => $this->home['employee']))
            ->action(function (array $data) {
                LeaveActions::apply($this->home['employee'], $data);
                app(UxMetrics::class)->record('leave.requested');
                unset($this->home);
            });
    }

    public function regulariseAction(): Action
    {
        return Action::make('regularise')->label('Fix my attendance')->icon(Heroicon::OutlinedClock)
            ->modalHeading('Fix my attendance')
            ->modalDescription('Tell your manager what happened; attendance is reprocessed once approved.')
            ->visible(fn () => $this->home['employee'] !== null && auth()->user()->can('attendance.regularise'))
            ->schema([
                // HROne parity RQ-2: whose request it is.
                RequestChrome::header(fn () => $this->home['employee']),
                DatePicker::make('date')->native(false)->required()->default(now()->subDay())->maxDate(now()),
                Select::make('type')->options(config('peopleos.attendance.regularisation_types'))->required(),
                TimePicker::make('in')->label('Actual in')->seconds(false),
                TimePicker::make('out')->label('Actual out')->seconds(false),
                Textarea::make('reason')->required()->maxLength(255),
                AttendanceActions::attachmentsField(),
                // HROne parity RQ-2: who approves it (a read-only preview), who is informed, and the attendance policy.
                RequestChrome::route('attendance_regularisation', fn () => $this->home['employee']),
                // HROne parity RQ-1: the person's own open requests of the time-office family.
                PendingRequestsPanel::make(fn () => $this->home['employee']),
            ])
            ->action(function (array $data) {
                try {
                    $in = $data['in'] ? $data['date'].' '.$data['in'] : null;
                    $out = $data['out'] ? $data['date'].' '.$data['out'] : null;
                    $request = app(Regularisations::class)->request($this->home['employee'], $data['date'], $data['type'], $data['reason'], $in, $out, auth()->user(), array_values((array) ($data['attachments'] ?? [])));
                    RequestChrome::confirmed('attendance_regularisation', $request, 'Correction sent to your manager');
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Cannot request')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public function wishAction(): Action
    {
        return BirthdayActions::wish(function () {
            unset($this->home);
        });
    }

    /** HROne setup parity HP-9a: wishes on a work or wedding anniversary from the celebrations card. */
    public function wishAnniversaryAction(): Action
    {
        return BirthdayActions::wishAnniversary(function () {
            unset($this->home);
        });
    }

    /** HROne setup parity HP-9f: the mood bot's question (one check-in per period; MoodCheckIns keeps it private). */
    public function moodCheckInAction(): Action
    {
        return Action::make('moodCheckIn')->label('Share my mood')->icon(Heroicon::OutlinedFaceSmile)
            ->modalHeading('How’s your mood today?')
            ->modalDescription(fn () => ['anonymous' => 'Your answer is anonymous: it is never linked to you, and results show only for groups large enough.',
                'confidential' => 'Your answer is confidential: results show only for groups large enough.', 'identified' => 'HR sees your answer.'][app(MoodCheckIns::class)->privacy()])
            ->modalSubmitActionLabel('Submit')
            ->fillForm(fn (array $arguments) => ['mood' => (int) ($arguments['mood'] ?? 3)])
            ->schema([
                Radio::make('mood')->label('Mood')->options(MoodCheckIn::MOODS)->required()->inline(),
                CheckboxList::make('reasons')->label('What is it about?')->options(fn () => app(MoodCheckIns::class)->reasons())->columns(2),
                Textarea::make('comment')->label('Comments')->rows(3)->maxLength(MoodCheckIns::COMMENT_MAX),
            ])
            ->action(function (array $data) {
                try {
                    $result = app(MoodCheckIns::class)->checkIn(auth()->user(), (int) $data['mood'], array_values((array) ($data['reasons'] ?? [])), $data['comment'] ?? null);
                    Notification::make()->success()->title($result['status'] === 'submitted' ? 'Thank you for sharing' : 'You already shared your mood for this period')->send();
                } catch (EngagementRuleViolation $e) {
                    Notification::make()->danger()->title('Not shared')->body($e->getMessage())->send();
                }
                unset($this->home);
            });
    }

    /** HROne setup parity HP-9e: "Like" on a post of the social wall card. */
    public function wallReact(int $post): void
    {
        try {
            app(SocialWall::class)->react(WallPost::query()->findOrFail($post), auth()->user(), 'like');
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();
        }
        unset($this->home);
    }

    public function askHrAction(): Action
    {
        return ServiceDeskActions::askHr()->label('Raise an HR request')->modalHeading('Raise an HR request')
            ->modalDescription('HR sees it in their queue with an SLA; you can follow it from My work.');
    }
}
