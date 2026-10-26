<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\PeopleVisibility;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\People\Actions\UpdatePersonAction;
use App\Domain\People\Models\Person;
use App\Domain\People\Services\Birthdays as BirthdayService;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Support\BirthdayActions;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Birthdays: today's birthdays with a wish button, the next two weeks, any month, and the wishes you received.
 * Colleagues see the day and month only, as the tenant shares them (BirthdayService::query); celebration managers see
 * everyone in their scope, download a month's list and set up greetings, nudges and the lists they receive.
 */
class Birthdays extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCake;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'Birthdays';

    protected static ?string $title = 'Birthdays';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.birthdays';

    public int $year;

    public int $month;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TenantContext::class)->has() && ($user->hasPermission('celebration.view') || $user->hasPermission('celebration.manage'));
    }

    public static function getNavigationBadge(): ?string
    {
        $birthdays = app(BirthdayService::class);
        $count = auth()->user() === null ? 0 : $birthdays->countOn($birthdays->today(), auth()->user());

        return $count > 0 ? (string) $count : null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $today = $this->birthdays()->today();
        $this->year = $today->year;
        $this->month = $today->month;
    }

    public function getSubheading(): ?string
    {
        return $this->manages()
            ? 'Everyone’s birthday in your scope, including people who keep theirs private. Colleagues see the day and month only.'
            : 'Wish a colleague on their day. Only the day and month are shown, never the year.';
    }

    public function manages(): bool
    {
        return $this->birthdays()->canSeeAll(auth()->user());
    }

    /** @return Collection<int, Employee> */
    #[Computed]
    public function today(): Collection
    {
        return $this->birthdays()->on($this->birthdays()->today(), auth()->user());
    }

    /** @return Collection<int, Employee> */
    #[Computed]
    public function upcoming(): Collection
    {
        return $this->birthdays()->upcoming($this->birthdays()->today(), 14, auth()->user());
    }

    /** @return Collection<int, Employee> */
    #[Computed]
    public function monthList(): Collection
    {
        return $this->birthdays()->inMonth($this->year, $this->month, auth()->user());
    }

    /** @return list<int> people already wished this year */
    #[Computed]
    public function wished(): array
    {
        return $this->birthdays()->wishedBy(auth()->user(), $this->birthdays()->today()->year);
    }

    /** @return list<int> people the viewer may open in the person drawer (others are plain names) */
    #[Computed]
    public function openable(): array
    {
        $ids = $this->today->pluck('id')->merge($this->upcoming->pluck('id'))->merge($this->monthList->pluck('id'))->unique()->values()->all();

        return $ids === [] ? [] : app(PeopleVisibility::class)->query(auth()->user())->whereKey($ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    #[Computed]
    public function me(): ?Employee
    {
        return app(RoleLens::class)->employee(auth()->user())?->loadMissing('person');
    }

    /** The wishes you received this year (shown from your birthday on). */
    #[Computed]
    public function myWishes(): Collection
    {
        return $this->me === null ? collect() : $this->birthdays()->wishesFor($this->me, $this->birthdays()->today()->year);
    }

    public function dateOf(Employee $employee): string
    {
        return BirthdayService::dateIn($employee->person->birthday_key, $this->year)->format('D j M');
    }

    public function nextOf(Employee $employee): string
    {
        $next = BirthdayService::next($employee->person->birthday_key, $this->birthdays()->today());
        $days = (int) $this->birthdays()->today()->diffInDays($next);

        return $next->format('D j M').' · '.($days === 1 ? 'tomorrow' : "in {$days} days");
    }

    public function canWish(Employee $employee): bool
    {
        return (int) $employee->user_id !== (int) auth()->id() && ! in_array($employee->id, $this->wished, true);
    }

    public function shiftMonth(int $by): void
    {
        $date = BirthdayService::dateIn($this->month * 100 + 1, $this->year)->addMonthsNoOverflow($by);
        $this->year = $date->year;
        $this->month = $date->month;
        unset($this->monthList, $this->openable);
    }

    public function wishAction(): Action
    {
        return BirthdayActions::wish(function () {
            unset($this->wished);
        });
    }

    /** Your own birthday: the day you celebrate (when the papers say otherwise) and whether colleagues see it. */
    public function myBirthdayAction(): Action
    {
        return Action::make('myBirthday')->label('My birthday')->icon(Heroicon::OutlinedCake)->color('gray')
            ->visible(fn () => $this->me !== null)
            ->modalHeading('My birthday')
            ->modalSubmitActionLabel('Save')
            ->modalDescription(fn () => $this->me?->person?->date_of_birth
                ? 'Your date of birth on record is '.$this->me->person->date_of_birth->format('j F').'. If you celebrate on another day, choose it here.'
                : 'Choose the day you celebrate. HR keeps your date of birth separately.')
            ->fillForm(fn () => $this->me->person->only(['birthday_month', 'birthday_day', 'birthday_shared']))
            ->schema(EmployeeForm::birthdayFields())
            ->action(function (array $data) {
                $data['birthday_day'] = filled($data['birthday_month'] ?? null) ? ($data['birthday_day'] ?? null) : null;
                app(UpdatePersonAction::class)->handle($this->me->person, array_intersect_key($data, array_flip(['birthday_month', 'birthday_day', 'birthday_shared'])), 'Birthday updated by the employee');
                unset($this->me, $this->today, $this->upcoming, $this->monthList);
                Notification::make()->success()->title('Birthday saved')->send();
            });
    }

    public function downloadAction(): Action
    {
        return Action::make('download')->label('Download month')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn () => $this->manages())
            ->action(function (): StreamedResponse {
                $rows = $this->monthList;
                $name = 'birthdays-'.sprintf('%04d-%02d', $this->year, $this->month).'.csv';

                return response()->streamDownload(function () use ($rows) {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['Date', 'Employee code', 'Name', 'Designation', 'Department', 'Work email', 'Shared with colleagues', 'Differs from date of birth']);
                    foreach ($rows as $e) {
                        fputcsv($out, [$this->dateOf($e), $e->employee_code, $e->person->display_name, $e->currentPosition?->designation?->name, $e->currentPosition?->department?->name, $e->work_email, $e->person->birthday_shared ? 'Yes' : 'No', $e->person->celebratesOtherDay() ? 'Yes' : 'No']);
                    }
                    fclose($out);
                }, $name, ['Content-Type' => 'text/csv']);
            });
    }

    public function settingsAction(): Action
    {
        $keys = ['visible_to', 'announce_to', 'greeting_enabled', 'greeting_message', 'digest_enabled', 'digest_lead_days', 'notify_admins_on_day', 'email'];

        return Action::make('settings')->label('Set up')->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
            ->visible(fn () => $this->manages())
            ->modalHeading('Birthday set-up')
            ->modalDescription('Applies to the whole organisation. Lists and day notices go to everyone who manages birthdays (permission: celebration.manage).')
            ->fillForm(fn () => collect($keys)->mapWithKeys(fn (string $k) => [$k => $this->birthdays()->setting($k)])->all())
            ->schema([
                Section::make('Colleagues')->columns(2)->schema([
                    Select::make('visible_to')->label('Colleagues see birthdays of')->options(BirthdayService::VISIBLE_TO)->required(),
                    Select::make('announce_to')->label('On the day, remind')->options(BirthdayService::ANNOUNCE_TO)->required()
                        ->helperText('An in-app note so they can send wishes. Never for people who keep their birthday private.'),
                ]),
                Section::make('Greeting to the employee')->schema([
                    Toggle::make('greeting_enabled')->label('Send our greeting on the morning of the birthday'),
                    Textarea::make('greeting_message')->label('Greeting')->rows(2)->maxLength(500)->required()
                        ->helperText('{name} is the first name, {organisation} your organisation’s name.'),
                ]),
                Section::make('Lists for HR')->columns(2)->schema([
                    Toggle::make('digest_enabled')->label('Send the month’s birthday list'),
                    TextInput::make('digest_lead_days')->label('Days before the month starts')->numeric()->integer()->minValue(0)->maxValue(25)->required()
                        ->helperText('0 = on the 1st of the month. 5 = on the 26th or 27th of the month before.'),
                    Toggle::make('notify_admins_on_day')->label('Also send the day’s list each morning'),
                    Toggle::make('email')->label('Email the lists and the greeting too (in-app always)'),
                ]),
            ])
            ->action(function (array $data) use ($keys) {
                $settings = app(SettingsRepository::class);
                foreach ($keys as $key) {
                    $value = $data[$key] ?? null;
                    $settings->set("celebration.birthdays.{$key}", is_bool($value) ? $value : ($key === 'digest_lead_days' ? (int) $value : (string) $value), 'Birthday set-up');
                }
                unset($this->today, $this->upcoming, $this->monthList, $this->openable);
                Notification::make()->success()->title('Birthday set-up saved')->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [$this->myBirthdayAction(), $this->downloadAction(), $this->settingsAction()];
    }

    private function birthdays(): BirthdayService
    {
        return app(BirthdayService::class);
    }

    /** Month names for the month switcher. */
    public function monthName(): string
    {
        return Person::MONTHS[$this->month].' '.$this->year;
    }
}
