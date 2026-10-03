<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\HomeComposer;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Experience\Services\UxMetrics;
use App\Domain\Platform\Models\Tenant;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Support\LeaveActions;
use App\Filament\Support\ServiceDeskActions;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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

    /** Switch the home lens (persisted; only lenses the person actually has). */
    public function switchLens(string $lens): void
    {
        if (! in_array($lens, app(RoleLens::class)->lenses(auth()->user()), true)) {
            return;
        }
        app(ExperiencePreferences::class)->update(auth()->user(), ['lens' => $lens]);
        unset($this->home);
    }

    /** First-login welcome (§47): shown once, until the person dismisses it. */
    public function showWelcome(): bool
    {
        return app(TenantContext::class)->has() && (app(ExperiencePreferences::class)->for(auth()->user())['welcomed_at'] ?? null) === null;
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

    public function punch(string $direction): void
    {
        $employee = $this->home['employee'];
        if ($employee === null || ! in_array($direction, ['in', 'out'], true)) {
            return;
        }
        try {
            app(PunchIngestion::class)->record($employee, now(), $direction, 'web');
            Notification::make()->success()->title($direction === 'in' ? 'Checked in' : 'Checked out')->body(now()->format('H:i'))->send();
            $this->dispatch('pos-success', message: $direction === 'in' ? 'Checked in' : 'Checked out');
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
                DatePicker::make('date')->native(false)->required()->default(now()->subDay())->maxDate(now()),
                Select::make('type')->options(config('peopleos.attendance.regularisation_types'))->required(),
                TimePicker::make('in')->label('Actual in')->seconds(false),
                TimePicker::make('out')->label('Actual out')->seconds(false),
                Textarea::make('reason')->required()->maxLength(255),
            ])
            ->action(function (array $data) {
                try {
                    $in = $data['in'] ? $data['date'].' '.$data['in'] : null;
                    $out = $data['out'] ? $data['date'].' '.$data['out'] : null;
                    app(Regularisations::class)->request($this->home['employee'], $data['date'], $data['type'], $data['reason'], $in, $out, auth()->user());
                    Notification::make()->success()->title('Correction sent to your manager')->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Cannot request')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public function askHrAction(): Action
    {
        return ServiceDeskActions::askHr()->label('Raise an HR request')->modalHeading('Raise an HR request')
            ->modalDescription('HR sees it in their queue with an SLA; you can follow it from My work.');
    }
}
