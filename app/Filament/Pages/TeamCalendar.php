<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Services\TeamCalendar as TeamCalendarEvents;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * HROne parity CA-9 (Access rights → Calendar access): the Team calendar. A manager sees their team's events, HR their
 * access scope's, each event only as its own domain allows (TeamCalendarEvents) and only the kinds the organisation
 * shows to that audience (Setup → Access rights → Calendar access). The leave calendar, birthdays page and the
 * employee's own attendance calendar are unchanged.
 */
class TeamCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $title = 'Team calendar';

    protected static ?string $slug = 'team-calendar';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.access.team-calendar';

    public string $audience = '';

    public int $year = 0;

    public int $month = 0;

    /** @var list<string> event kinds the viewer narrowed the month to (none = every kind shown to them) */
    public array $only = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ! $user->isPlatformAdmin() && app(TenantContext::class)->has()
            && app(TeamCalendarEvents::class)->audiences($user) !== [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $today = CarbonImmutable::now();
        $this->year = $today->year;
        $this->month = $today->month;
        $this->audience = (string) array_key_first($this->audiences());
    }

    /** @return array<string, string> */
    public function audiences(): array
    {
        return app(TeamCalendarEvents::class)->audiences(auth()->user());
    }

    /** @return array<string, string> */
    public function kinds(): array
    {
        return app(TeamCalendarEvents::class)->kinds(auth()->user(), $this->audience);
    }

    public function setAudience(string $audience): void
    {
        if (array_key_exists($audience, $this->audiences())) {
            $this->audience = $audience;
            $this->only = [];
            unset($this->days);
        }
    }

    public function shiftMonth(int $by): void
    {
        $date = CarbonImmutable::create($this->year, max(1, min(12, $this->month)), 1)->addMonths(max(-12, min(12, $by)));
        $this->year = $date->year;
        $this->month = $date->month;
        unset($this->days);
    }

    public function toggleKind(string $kind): void
    {
        if (! array_key_exists($kind, $this->kinds())) {
            return;
        }
        $this->only = in_array($kind, $this->only, true) ? array_values(array_diff($this->only, [$kind])) : [...$this->only, $kind];
        unset($this->days);
    }

    public function monthName(): string
    {
        return CarbonImmutable::create($this->year, max(1, min(12, $this->month)), 1)->format('F Y');
    }

    /** @return array<string, list<array{kind: string, label: string, name: string, employee_id: int, detail: ?string}>> */
    #[Computed]
    public function days(): array
    {
        abort_unless(static::canAccess(), 403);

        return app(TeamCalendarEvents::class)->events(auth()->user(), $this->audience, $this->year, $this->month, $this->only);
    }
}
