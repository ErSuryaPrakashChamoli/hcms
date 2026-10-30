<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\PeopleVisibility;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\People\Actions\UpdatePersonAction;
use App\Domain\People\Models\Person;
use App\Domain\People\Services\Anniversaries;
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
 *
 * HROne setup parity HP-9a: when the organisation celebrates work or wedding anniversaries, the page becomes
 * "Celebrations" with one tab per occasion (the same today / coming up / month lists and wishes, Anniversaries).
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

    /** birthday, or an anniversary kind the organisation celebrates (HP-9a). */
    public string $kind = 'birthday';

    /** @return array<string, string> the occasions this page shows (birthdays first) */
    public static function kinds(): array
    {
        $kinds = ['birthday' => 'Birthdays'];
        foreach (app(Anniversaries::class)->enabledKinds() as $kind) {
            $kinds[$kind] = Anniversaries::PLURAL[$kind];
        }

        return $kinds;
    }

    public static function getNavigationLabel(): string
    {
        return app(TenantContext::class)->has() && count(self::kinds()) > 1 ? 'Celebrations' : 'Birthdays';
    }

    public function getTitle(): string
    {
        return count(self::kinds()) > 1 ? 'Celebrations' : 'Birthdays';
    }

    public function selectKind(string $kind): void
    {
        if (array_key_exists($kind, self::kinds())) {
            $this->kind = $kind;
            unset($this->openable);
        }
    }

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
        if ($this->kind !== 'birthday') {
            return $this->manages()
                ? 'Everyone’s '.strtolower(Anniversaries::PLURAL[$this->kind]).' in your scope'.($this->kind === 'wedding_anniversary' ? ', including people who keep theirs private.' : '.')
                : 'Wish a colleague on their day.';
        }

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
        $ids = $this->kind === 'birthday'
            ? $this->today->pluck('id')->merge($this->upcoming->pluck('id'))->merge($this->monthList->pluck('id'))->unique()->values()->all()
            : $this->anniversariesToday->pluck('id')->merge($this->anniversariesUpcoming->pluck('id'))->merge($this->anniversariesMonth->pluck('id'))->unique()->values()->all();

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

    /** @return Collection<int, Employee> (HP-9a) */
    #[Computed]
    public function anniversariesToday(): Collection
    {
        return $this->kind === 'birthday' ? collect() : $this->anniversaries()->on($this->kind, $this->birthdays()->today(), auth()->user());
    }

    /** @return Collection<int, Employee> */
    #[Computed]
    public function anniversariesUpcoming(): Collection
    {
        return $this->kind === 'birthday' ? collect() : $this->anniversaries()->upcoming($this->kind, $this->birthdays()->today(), 14, auth()->user());
    }

    /** @return Collection<int, Employee> */
    #[Computed]
    public function anniversariesMonth(): Collection
    {
        return $this->kind === 'birthday' ? collect() : $this->anniversaries()->inMonth($this->kind, $this->year, $this->month, auth()->user());
    }

    /** @return list<int> people already wished for this occasion this year */
    #[Computed]
    public function anniversariesWished(): array
    {
        return $this->kind === 'birthday' ? [] : $this->anniversaries()->wishedBy($this->kind, auth()->user(), $this->birthdays()->today()->year);
    }

    /** The anniversary wishes you received this year. */
    #[Computed]
    public function myAnniversaryWishes(): Collection
    {
        return $this->kind === 'birthday' || $this->me === null ? collect() : $this->anniversaries()->wishesFor($this->kind, $this->me, $this->birthdays()->today()->year);
    }

    /** "Mon 3 Nov · 3 years" (work anniversaries carry the years completed that day); $when: today | next | month. */
    public function anniversaryLine(Employee $employee, string $when = 'today'): string
    {
        $today = $this->birthdays()->today();
        $date = match ($when) {
            'next' => $this->anniversaries()->next($this->kind, $employee, $today),
            'month' => Anniversaries::dateIn($this->kind, $employee, $this->year),
            default => Anniversaries::dateIn($this->kind, $employee, $today->year),
        };
        $line = $when === 'today' ? 'Today' : $date->format('D j M');
        if ($when === 'next') {
            $days = (int) $today->diffInDays($date);
            $line .= ' · '.($days === 1 ? 'tomorrow' : "in {$days} days");
        }

        return $this->kind === 'work_anniversary' ? $line.' · '.Anniversaries::yearsLabel(Anniversaries::years($employee, $date)) : $line;
    }

    public function canWishAnniversary(Employee $employee): bool
    {
        return (int) $employee->user_id !== (int) auth()->id() && ! in_array($employee->id, $this->anniversariesWished, true);
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
        unset($this->monthList, $this->anniversariesMonth, $this->openable);
    }

    public function wishAction(): Action
    {
        return BirthdayActions::wish(function () {
            unset($this->wished);
        });
    }

    public function wishAnniversaryAction(): Action
    {
        return BirthdayActions::wishAnniversary(function () {
            unset($this->anniversariesWished);
        });
    }

    /** HP-9a: your own wedding anniversary (day and month) and whether colleagues see it, while the organisation celebrates them. */
    public function myAnniversaryAction(): Action
    {
        return Action::make('myAnniversary')->label('My wedding anniversary')->icon(Heroicon::OutlinedHeart)->color('gray')
            ->visible(fn () => $this->me !== null && $this->kind === 'wedding_anniversary')
            ->modalHeading('My wedding anniversary')
            ->modalSubmitActionLabel('Save')
            ->modalDescription('Day and month only. Colleagues see it only when you choose to show it; HR always does.')
            ->fillForm(fn () => $this->me->person->only(['wedding_anniversary_month', 'wedding_anniversary_day', 'wedding_anniversary_shared']))
            ->schema(EmployeeForm::weddingAnniversaryFields())
            ->action(function (array $data) {
                $data['wedding_anniversary_day'] = filled($data['wedding_anniversary_month'] ?? null) ? ($data['wedding_anniversary_day'] ?? null) : null;
                app(UpdatePersonAction::class)->handle($this->me->person, array_intersect_key($data, array_flip(['wedding_anniversary_month', 'wedding_anniversary_day', 'wedding_anniversary_shared'])), 'Wedding anniversary updated by the employee');
                unset($this->me, $this->anniversariesToday, $this->anniversariesUpcoming, $this->anniversariesMonth);
                Notification::make()->success()->title('Wedding anniversary saved')->send();
            });
    }

    /**
     * HP-9a: the messages employees receive, as the organisation has written them, filled in for you (HROne's "Preview
     * message"): the birthday greeting, each celebrated anniversary's greeting and the first-login welcome.
     *
     * @return list<array{label: string, text: string}>
     */
    public function previewMessages(): array
    {
        $first = $this->me?->person?->first_name ?? strtok((string) auth()->user()->name, ' ');
        $messages = [];
        if ($this->birthdays()->setting('greeting_enabled')) {
            $messages[] = ['label' => 'Birthday greeting', 'text' => $this->birthdays()->render((string) $this->birthdays()->setting('greeting_message'), (string) $first)];
        }
        foreach ($this->anniversaries()->enabledKinds() as $kind) {
            $messages[] = ['label' => Anniversaries::KINDS[$kind].' greeting', 'text' => $this->birthdays()->render((string) $this->anniversaries()->setting($kind, 'greeting_message'), (string) $first, ['{years}' => Anniversaries::yearsLabel(3)])];
        }
        $settings = app(SettingsRepository::class);
        if ($settings->get('experience.welcome.enabled')) {
            $messages[] = ['label' => 'Welcome at first sign-in', 'text' => filled($settings->get('experience.welcome.message'))
                ? $this->birthdays()->render((string) $settings->get('experience.welcome.message'), (string) $first) : 'Welcome to PeopleOS. Your work comes to you here. (PeopleOS’s own welcome)'];
        }

        return $messages;
    }

    public function previewAction(): Action
    {
        return Action::make('preview')->label('Preview messages')->icon(Heroicon::OutlinedEye)->color('gray')
            ->visible(fn () => $this->manages())
            ->modalHeading('Messages employees receive')
            ->modalDescription('Filled in with your name; work anniversaries are shown for 3 years. Change the wording in Set up or Setup → Engage.')
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalContent(fn () => view('filament.pages.birthdays.preview', ['messages' => $this->previewMessages()]));
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
                if ($this->kind !== 'birthday') {
                    return $this->downloadAnniversaries();
                }
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

    /** HP-9a: the month's work or wedding anniversaries as CSV (celebration managers). */
    private function downloadAnniversaries(): StreamedResponse
    {
        $rows = $this->anniversariesMonth;
        $kind = $this->kind;
        $name = str_replace('_', '-', $kind).'s-'.sprintf('%04d-%02d', $this->year, $this->month).'.csv';

        return response()->streamDownload(function () use ($rows, $kind) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Employee code', 'Name', 'Designation', 'Department', 'Work email', ...($kind === 'work_anniversary' ? ['Joining date', 'Years'] : ['Shared with colleagues'])]);
            foreach ($rows as $e) {
                $date = Anniversaries::dateIn($kind, $e, $this->year);
                fputcsv($out, [$date->format('D j M'), $e->employee_code, $e->person->display_name, $e->currentPosition?->designation?->name, $e->currentPosition?->department?->name, $e->work_email,
                    ...($kind === 'work_anniversary' ? [$e->joining_date->toDateString(), Anniversaries::years($e, $date)] : [$e->person->wedding_anniversary_shared ? 'Yes' : 'No'])]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
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
        return [$this->myBirthdayAction(), $this->myAnniversaryAction(), $this->downloadAction(), $this->previewAction(), $this->settingsAction()];
    }

    private function birthdays(): BirthdayService
    {
        return app(BirthdayService::class);
    }

    private function anniversaries(): Anniversaries
    {
        return app(Anniversaries::class);
    }

    /** Month names for the month switcher. */
    public function monthName(): string
    {
        return Person::MONTHS[$this->month].' '.$this->year;
    }
}
