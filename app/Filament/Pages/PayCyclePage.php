<?php

namespace App\Filament\Pages;

use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Services\PayCycle;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use UnitEnum;

/**
 * Pay cycle (tenant setting payroll.cycle_start_day): the day every payroll month starts on, for clients who run
 * attendance and payroll from the 25th to the 24th rather than the calendar month, with what the next months cover.
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
        $this->form->fill(['start_day' => app(PayCycle::class)->startDay()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make()->schema([
                Select::make('start_day')->label('Each payroll month starts on')->options(self::options())->required()->live()
                    ->helperText('A month is named after the month it ends in: starting on the 25th, November runs from 25 October to 24 November.'),
                AuditReasonField::make(),
            ]),
        ]);
    }

    /** @return array<int, string> day => "25th: from the 25th of the month before to the 24th" */
    public static function options(): array
    {
        $options = [1 => '1st: calendar month (1st to last day)'];
        for ($day = 2; $day <= PayCycle::MAX_DAY; $day++) {
            $options[$day] = PayCycle::ordinal($day).': from the '.PayCycle::ordinal($day).' of the month before to the '.PayCycle::ordinal($day - 1);
        }

        return $options;
    }

    /**
     * What the coming months cover for each company under the start day on the form (not yet saved).
     *
     * @return list<array{company: string, months: list<array<string, mixed>>}>
     */
    public function preview(): array
    {
        $day = PayCycle::normalise($this->data['start_day'] ?? null);

        return Company::query()->orderBy('name')->get()
            ->map(fn (Company $company) => ['company' => $company->name, 'months' => app(PayCycle::class)->preview($company->id, $day)])->all();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label('Save pay cycle')->action('save')];
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $state = $this->form->getState();
        $reason = AuditReasonField::extract($state);
        $day = PayCycle::normalise($state['start_day'] ?? null);
        $cycle = app(PayCycle::class);

        if ($day === $cycle->startDay()) {
            Notification::make()->title('No change: payroll months already run '.PayCycle::describe($day))->send();

            return;
        }
        try {
            $cycle->change($day, $reason);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Pay cycle not saved')->body($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title('Pay cycle saved: '.PayCycle::describe($day))
            ->body('Months that already have a payroll run keep their dates; the month after them starts the day after they end.')->send();
        $this->form->fill(['start_day' => $day]);
    }
}
