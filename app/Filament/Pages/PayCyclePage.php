<?php

namespace App\Filament\Pages;

use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Services\PayCycle;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * Pay cycle (tenant setting payroll.pay_cycle): an admin picks the date a new cycle starts, for clients who run
 * attendance and payroll from the 25th to the 24th rather than the calendar month, and sees what the months around it
 * will cover before saving.
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
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Start a new pay cycle')
                ->description('Pick the first day of the first month on the new cycle; that day of the month then starts every month. Pick 25 Nov 2026 and December runs from 25 November to 24 December, January from 25 December to 24 January. Pick the 1st of a month to go back to calendar months.')
                ->schema([
                    DatePicker::make('starts_on')->label('New cycle starts on')->native(false)->displayFormat('j M Y')->closeOnDateSelection()
                        ->minDate(fn () => $this->earliestStart())->maxDate(fn () => today()->addYears(2))
                        ->disabledDates(fn () => $this->lateDays())->required()->live()
                        ->helperText(fn () => $this->outcome()),
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
                'delta' => $next === null ? 'Pick a date below to schedule one' : 'Then '.($next['day'] === 1 ? 'calendar months' : PayCycle::describe($next['day']))],
        ];
    }

    /**
     * What each company's months cover around the date on the form (before it is saved), or from today's month.
     *
     * @return list<array{company: string, months: list<array<string, mixed>>}>
     */
    public function preview(): array
    {
        $cycle = app(PayCycle::class);
        $from = $this->pickedDate();
        $changes = $from !== null ? $cycle->withChange($from) : null;
        [$year, $month] = $from !== null ? [intdiv(PayCycle::firstMonth($from) - 2, 12), (PayCycle::firstMonth($from) - 2) % 12 + 1] : $cycle->monthOf(today());

        return Company::query()->orderBy('name')->get()
            ->map(fn (Company $company) => ['company' => $company->name, 'months' => $cycle->preview($company->id, $year, $month, $changes)])->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save pay cycle')->action('save'),
            Action::make('cancelScheduled')->label('Cancel scheduled change')->color('gray')->icon(Heroicon::OutlinedXMark)
                ->visible(fn () => app(PayCycle::class)->scheduled() !== [])
                ->requiresConfirmation()
                ->modalDescription(fn () => 'The change starting '.(app(PayCycle::class)->scheduled()[0]['from'] ?? today())->format('j M Y').' is removed, and the months stay on the cycle in force now.')
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
        $from = CarbonImmutable::parse($state['starts_on']);

        try {
            $changed = app(PayCycle::class)->change($from, $reason);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Pay cycle not saved')->body($e->getMessage())->persistent()->send();

            return;
        }
        if (! $changed) {
            Notification::make()->title('No change: from '.$from->format('j M Y').' months already run '.($from->day === 1 ? 'as calendar months' : PayCycle::describe($from->day)))->send();

            return;
        }

        Notification::make()->success()->title('Pay cycle saved')
            ->body('From '.$from->format('j M Y').', payroll months run '.($from->day === 1 ? 'as calendar months' : 'from the '.PayCycle::describe($from->day)).'.')->send();
        $this->form->fill();
    }

    /** The day after the last month with a payroll run: a new cycle cannot move a month that already has one. */
    private function earliestStart(): ?CarbonImmutable
    {
        return app(PayCycle::class)->fixedUntil()?->addDay();
    }

    /** @return list<string> the 29th to the 31st in the months the picker shows: a cycle starts by the 28th */
    private function lateDays(): array
    {
        $dates = [];
        for ($month = ($this->earliestStart() ?? today()->toImmutable()->subYears(2))->startOfMonth(), $last = today()->addYears(2); $month->lte($last); $month = $month->addMonthNoOverflow()) {
            for ($day = PayCycle::MAX_DAY + 1; $day <= $month->daysInMonth; $day++) {
                $dates[] = $month->day($day)->toDateString();
            }
        }

        return $dates;
    }

    /** What the date on the form would do, in one sentence. */
    private function outcome(): string
    {
        $from = $this->pickedDate();
        if ($from === null) {
            $earliest = $this->earliestStart();

            return 'Months that already have a payroll run keep their dates'.($earliest !== null ? ', so the earliest date is '.$earliest->format('j M Y') : '').'. The 29th to the 31st cannot start a cycle.';
        }
        $first = PayCycle::firstMonth($from);

        return ($from->day === 1 ? 'Payroll months go back to calendar months' : 'Payroll months will run from the '.PayCycle::describe($from->day))
            .', starting with '.CarbonImmutable::create(intdiv($first, 12), $first % 12 + 1, 1)->format('F Y').'. The months below show the dates, the changeover month included.';
    }

    /** The date on the form when it can start a cycle, else null. */
    private function pickedDate(): ?CarbonImmutable
    {
        try {
            $from = filled($this->data['starts_on'] ?? null) ? CarbonImmutable::parse($this->data['starts_on'])->startOfDay() : null;
        } catch (Throwable) {
            return null;
        }
        $earliest = $this->earliestStart();

        return $from !== null && $from->day <= PayCycle::MAX_DAY && ($earliest === null || $from->gte($earliest)) ? $from : null;
    }

    private static function range(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->format($start->year === $end->year ? 'j M' : 'j M Y').' – '.$end->format('j M Y');
    }
}
