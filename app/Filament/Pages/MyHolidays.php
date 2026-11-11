<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Services\HolidayYear;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Identity\Services\CurrentEmployee;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * HROne parity MS-12 (My space → Holiday calendar): "Holidays for year, <year>" — the signed-in employee's holidays of a
 * calendar year from the calendar that applies to them (HolidayYear over HolidayResolver), tabs "Fixed holidays (n)" /
 * "Restricted holidays (n)" with name, weekday, date and type, the choice made for each restricted holiday, the year's
 * quota and the calendar's note for employees. "Choose" opens the existing time-office request form on the restricted
 * holiday kind with the holiday filled in (RequestRegistry's restricted-holiday type: offered only when the module offers
 * it; the module's own form, rules, approval and audit apply). Own record only (no employee id in the URL).
 */
class MyHolidays extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My holidays';

    protected static ?string $title = 'My holidays';

    protected static ?string $slug = 'my-holidays';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.my-holidays';

    public const TABS = ['fixed' => 'Fixed holidays', 'restricted' => 'Restricted holidays'];

    #[Url]
    public ?int $year = null;

    #[Url]
    public string $tab = 'fixed';

    public function getSubheading(): ?string
    {
        return 'The holidays of the year, from the calendar that applies to you.';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && self::me() !== null && ($user->can('attendance.regularise') || $user->can('leave.apply') || $user->can('attendance.view'));
    }

    public static function me(): ?Employee
    {
        return auth()->check() ? app(CurrentEmployee::class)->of(auth()->user()) : null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->normalise();
    }

    public function updatedYear(): void
    {
        $this->normalise();
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'fixed';
    }

    /** @return list<int> */
    public function years(): array
    {
        return app(HolidayYear::class)->years(self::me());
    }

    /** @return array<string, mixed> */
    public function holidays(): array
    {
        return app(HolidayYear::class)->for(self::me(), (int) $this->year);
    }

    /** "Choose": the time-office request form on the restricted holiday kind, with this holiday (null when not offered). */
    public function chooseUrl(array $holiday): ?string
    {
        if ($holiday['choice'] !== 'not_chosen' || $holiday['past']) {
            return null;
        }
        $entry = collect(app(RequestRegistry::class)->available(auth()->user()))->first(fn (array $e) => $e['type']->key() === 'time_office.restricted_holiday');
        if ($entry === null) {
            return null;
        }
        $url = (string) $entry['url'];

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['actionArguments' => ['holiday_id' => $holiday['id']]]);
    }

    public function calendarUrl(): ?string
    {
        return AttendanceCalendar::canAccess() ? AttendanceCalendar::getUrl() : null;
    }

    private function normalise(): void
    {
        $years = $this->years();
        if (! in_array((int) $this->year, $years, true)) {
            $this->year = (int) now()->year;
        }
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'fixed';
        }
    }
}
