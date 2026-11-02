<?php

namespace App\Filament\Pages;

use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Services\PayCycle;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * Pay cycle (tenant setting payroll.pay_cycle): an admin picks the date a new cycle starts and the payroll month it
 * runs to (or until changed), for clients who run attendance and payroll from the 25th to the 24th rather than the
 * calendar month, and sees what the months around it will cover before saving. With no payroll run yet (a first set
 * up) the cycle can start up to two years back.
 */
class PayCyclePage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Pay cycle';

    protected static ?string $title = 'Pay cycle';

    protected static ?string $slug = 'pay-cycle';

    protected static ?int $navigationSort = 45;

    protected string $view = 'filament.pages.pay-cycle';

    /** Months a cycle can start back (with no payroll run yet) and ahead of today's month. */
    private const MONTHS_BACK = 24;

    private const MONTHS_AHEAD = 24;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('payroll.manage') ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'The days each payroll month covers, for every company. Attendance, loss of pay, leave and proration are read for those days, and the monthly attendance upload follows them.';
    }

    public function mount(): void
    {
        $this->form->fill(['until' => 'open']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Set the pay cycle')
                ->description('Pick the first day of the first month on the cycle, and the payroll month it runs to. That day of the month then starts every month: pick 25 Nov 2026 and December runs from 25 November to 24 December, January from 25 December to 24 January. Pick the 1st of a month for calendar months.')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        DatePicker::make('starts_on')->label('From: the cycle starts on')->native(false)->displayFormat('j M Y')->closeOnDateSelection()
                            ->minDate(fn () => $this->firstAllowed())->maxDate(fn () => today()->addYears(2))
                            ->disabledDates(fn () => $this->lateDays())->required()->live()
                            ->helperText(fn () => $this->startHelp())
                            ->afterStateUpdated(fn (Get $get, Set $set) => array_key_exists((string) $get('until'), $this->untilOptions()) ? null : $set('until', 'open')),
                        Select::make('until')->label('To payroll month')->required()->live()->native(false)->searchable()
                            ->options(fn () => $this->untilOptions())
                            ->helperText('Pick the same month for one month only. After the last month, months go back to the cycle they had.'),
                    ]),
                    Text::make(fn () => $this->outcome()),
                    AuditReasonField::make(),
                ]),
        ]);
    }

    /**
     * The cycle in force, the payroll month holding today and the next scheduled change.
     *
     * @return list<array{value: string, label: string, delta: string}>
     */
    public function summary(): array
    {
        $cycle = app(PayCycle::class);
        $inForce = $cycle->inForce();
        [$year, $month] = $cycle->monthOf(today());
        [$start, $end] = $cycle->window($year, $month);
        $next = $cycle->scheduled()[0] ?? null;

        return [
            ['value' => $inForce === null || $inForce['day'] === 1 ? 'Calendar month' : PayCycle::describe($inForce['day']), 'label' => 'Pay cycle in force',
                'delta' => $inForce === null ? 'Since the start' : 'Since '.$inForce['from']->format('j M Y')],
            ['value' => CarbonImmutable::create($year, $month, 1)->format('M Y'), 'label' => 'Payroll month holding today', 'delta' => self::range($start, $end)],
            ['value' => $next === null ? 'None' : $next['from']->format('j M Y'), 'label' => 'Next change',
                'delta' => $next === null ? 'Pick the months below to schedule one' : 'Then '.($next['day'] === 1 ? 'calendar months' : PayCycle::describe($next['day']))],
        ];
    }

    /**
     * What each company's months cover around the months on the form (before they are saved), or from today's month.
     *
     * @return list<array{company: string, months: list<array<string, mixed>>}>
     */
    public function preview(): array
    {
        $cycle = app(PayCycle::class);
        $from = $this->pickedStart();
        $last = $this->pickedUntil();
        $changes = $from !== null ? $cycle->withChange($from, $last) : null;
        $start = $from !== null ? PayCycle::firstMonth($from) - 2 : PayCycle::monthIndex(...$cycle->monthOf(today()));
        $count = $last !== null ? $last + 2 - $start : 5; // to the month after the last
        $months = fn (Company $company, int $at, int $n) => $cycle->preview($company->id, intdiv($at, 12), $at % 12 + 1, $changes, $n);

        // A long run of months shows its first and last months, and the months between as one card.
        return Company::query()->orderBy('name')->get()
            ->map(fn (Company $company) => ['company' => $company->name, 'months' => $count <= 8 ? $months($company, $start, max(5, $count)) : [
                ...$months($company, $start, 4),
                ['label' => self::monthName($start + 4).' – '.self::monthName($last - 1), 'state' => 'gap', 'note' => 'On the '.PayCycle::describe($from->day)],
                ...$months($company, $last, 2),
            ]])->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save pay cycle')->action('save'),
            Action::make('cancelScheduled')->label('Cancel scheduled change')->color('gray')->icon(Heroicon::OutlinedXMark)
                ->visible(fn () => app(PayCycle::class)->scheduled() !== [])
                ->requiresConfirmation()
                ->modalDescription(fn () => (count(app(PayCycle::class)->scheduled()) > 1 ? 'The changes from ' : 'The change starting ').(app(PayCycle::class)->scheduled()[0]['from'] ?? today())->format('j M Y')
                    .(count(app(PayCycle::class)->scheduled()) > 1 ? ' are' : ' is').' removed, and the months stay on the cycle in force now.')
                ->action(function () {
                    abort_unless(self::canAccess(), 403);
                    app(PayCycle::class)->cancelScheduled();
                    Notification::make()->success()->title('Scheduled pay cycle change cancelled')->send();
                }),
        ];
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $state = $this->form->getState();
        $reason = AuditReasonField::extract($state);
        $from = $this->pickedStart();
        $until = $this->pickedUntil();
        if ($from === null) {
            Notification::make()->danger()->title('Pay cycle not saved')->body('Pick a date from the 1st to the 28th, after the months that have a payroll run.')->send();

            return;
        }

        try {
            $changed = app(PayCycle::class)->change($from, $reason, $until);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Pay cycle not saved')->body($e->getMessage())->persistent()->send();

            return;
        }
        if (! $changed) {
            Notification::make()->title('No change: from '.$from->format('j M Y').' months already run '.($from->day === 1 ? 'as calendar months' : PayCycle::describe($from->day)))->send();

            return;
        }

        Notification::make()->success()->title('Pay cycle saved')
            ->body('From '.$from->format('j M Y').', payroll months run '.($from->day === 1 ? 'as calendar months' : 'from the '.PayCycle::describe($from->day))
                .($until !== null ? ' to '.self::monthName($until).', then go back to the cycle they had' : '').'.')->send();
        $this->form->fill(['until' => 'open']);
    }

    /** The day after the last month with a payroll run: a new cycle cannot move a month that already has one. */
    private function earliestStart(): ?CarbonImmutable
    {
        return app(PayCycle::class)->fixedUntil()?->addDay();
    }

    /** The first date a cycle can start on: after the months with a payroll run, else two years back (a first set up). */
    private function firstAllowed(): CarbonImmutable
    {
        return $this->earliestStart() ?? CarbonImmutable::create(intdiv(self::thisMonth() - self::MONTHS_BACK, 12), (self::thisMonth() - self::MONTHS_BACK) % 12 + 1, 1);
    }

    /** @return list<string> the 29th to the 31st in the months the picker shows: a cycle starts by the 28th */
    private function lateDays(): array
    {
        $dates = [];
        for ($month = $this->firstAllowed()->startOfMonth(), $last = today()->addYears(2); $month->lte($last); $month = $month->addMonthNoOverflow()) {
            for ($day = PayCycle::MAX_DAY + 1; $day <= $month->daysInMonth; $day++) {
                $dates[] = $month->day($day)->toDateString();
            }
        }

        return $dates;
    }

    /** Under the date: the first month it gives, else how far back it can go. */
    private function startHelp(): string
    {
        $from = $this->pickedStart();
        if ($from !== null) {
            $first = PayCycle::firstMonth($from);

            return 'First month on it: '.self::monthName($first, 'F Y').', '.self::range(...PayCycle::cycleWindow(intdiv($first, 12), $first % 12 + 1, $from->day)).'.';
        }

        return ($this->earliestStart() === null
            ? 'No payroll run yet, so it can start on any day from '.$this->firstAllowed()->format('j M Y')
            : 'Months up to '.$this->earliestStart()->subDay()->format('j M Y').' have a payroll run and keep their dates').'. The 29th to the 31st cannot start a cycle.';
    }

    /** @return array<string, string> "open" => "Until changed", then the months from the first month on, the ends of financial years marked */
    private function untilOptions(): array
    {
        $from = $this->pickedStart();
        $options = ['open' => 'Until changed (no end)'];
        if ($from === null) {
            return $options;
        }
        $first = PayCycle::firstMonth($from);
        for ($at = $first; $at <= $first + self::MONTHS_AHEAD + 11; $at++) {
            $options[self::key($at)] = self::monthName($at).match (true) {
                $at === $first => ' (this month only)',
                $at === PayCycle::financialYearEnd($at) => ' (end of financial year '.self::financialYearName($at).')',
                default => '',
            };
        }

        return $options;
    }

    /** What the months on the form would do, in one sentence. */
    private function outcome(): string
    {
        $from = $this->pickedStart();
        if ($from === null) {
            return 'Pick the date the cycle starts and the month it runs to. Months that already have a payroll run keep their dates.';
        }
        $first = PayCycle::firstMonth($from);
        $last = $this->pickedUntil();
        $after = $last !== null ? PayCycle::dayAt(app(PayCycle::class)->withChange($from, $last), $last + 1) : $from->day;
        if ($last === null || $after === $from->day) {
            return ($from->day === 1 ? 'Payroll months go back to calendar months' : 'Payroll months will run from the '.PayCycle::describe($from->day))
                .', starting with '.self::monthName($first, 'F Y').'. The months below show the dates, the changeover month included.';
        }

        return 'Payroll months will run '.($from->day === 1 ? 'as calendar months' : 'from the '.PayCycle::describe($from->day))
            .($last === $first ? ' for '.self::monthName($first, 'F Y').' only' : ' from '.self::monthName($first, 'F Y').' to '.self::monthName($last, 'F Y'))
            .', then go back to '.($after === 1 ? 'calendar months' : 'the '.PayCycle::describe($after)).'. The months below show the dates, the changeover months included.';
    }

    /** The date on the form when it can start a cycle, else null. */
    private function pickedStart(): ?CarbonImmutable
    {
        try {
            $from = filled($this->data['starts_on'] ?? null) ? CarbonImmutable::parse($this->data['starts_on'])->startOfDay() : null;
        } catch (Throwable) {
            return null;
        }
        $earliest = $this->earliestStart();

        return $from !== null && $from->day <= PayCycle::MAX_DAY && ($earliest === null || $from->gte($earliest)) ? $from : null;
    }

    /** The last month on the form (year * 12 + month - 1), or null until changed. */
    private function pickedUntil(): ?int
    {
        $from = $this->pickedStart();
        $until = self::at($this->data['until'] ?? null);

        return $from !== null && $until !== null && $until >= PayCycle::firstMonth($from) ? $until : null;
    }

    private static function thisMonth(): int
    {
        return PayCycle::monthIndex(today()->year, today()->month);
    }

    /** A month (year * 12 + month - 1) as "2026-12". */
    private static function key(int $at): string
    {
        return sprintf('%04d-%02d', intdiv($at, 12), $at % 12 + 1);
    }

    /** "2026-12" as a month (year * 12 + month - 1), else null. */
    private static function at(mixed $key): ?int
    {
        return is_string($key) && preg_match('/^(\d{4})-(\d{2})$/', $key, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 12
            ? PayCycle::monthIndex((int) $m[1], (int) $m[2]) : null;
    }

    /** A month (year * 12 + month - 1) as "Mar 2027". */
    private static function monthName(int $at, string $format = 'M Y'): string
    {
        return CarbonImmutable::create(intdiv($at, 12), $at % 12 + 1, 1)->format($format);
    }

    /** The financial year ending in a month, "2026-27" (or "2026" for one ending in December). */
    private static function financialYearName(int $end): string
    {
        $startYear = intdiv($end - 11, 12);

        return $startYear === intdiv($end, 12) ? (string) $startYear : $startYear.'-'.substr((string) intdiv($end, 12), -2);
    }

    private static function range(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->format($start->year === $end->year ? 'j M' : 'j M Y').' – '.$end->format('j M Y');
    }
}
