<?php

namespace App\Filament\Resources\TaxIncomeEntries\Pages;

use App\Domain\Compliance\Models\TaxIncomeEntry;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\TaxIncomeEntries;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\TaxIncomeEntries\TaxIncomeEntryResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PayrollRegisters;
use App\Filament\Support\TimeOfficeRegisters;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListTaxIncomeEntries extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = TaxIncomeEntryResource::class;

    public static function registerKey(): string
    {
        return 'tax_income_entries';
    }

    public static function registerReports(): array
    {
        return ['perquisite_register', 'income_outside_payroll'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the dataset (labels) */
    public function registerReportFilters(): array
    {
        return TimeOfficeRegisters::reportFilters((array) ($this->tableFilters ?? []), [
            'status' => ['status', fn (string $v) => TaxIncomeEntry::STATUSES[$v] ?? $v],
            'kind' => ['kind', fn (string $v) => TaxIncomeEntry::KINDS[$v] ?? $v],
        ]);
    }

    protected function getHeaderActions(): array
    {
        $run = fn (Closure $call, string $done) => function (array $data) use ($call, $done) {
            try {
                $call($data);
                Notification::make()->success()->title($done)->body('The next payroll calculation counts it in TDS.')->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->send();
            }
        };
        $fy = app(FinancialYear::class);
        $years = collect([now()->subYear(), now(), now()->addYear()])->mapWithKeys(fn ($d) => [$fy->label($d) => $fy->label($d)])->all();
        $employee = Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required();

        return [
            // HROne parity PO-1: views, report and the register's uploads.
            ...$this->registerToolActions(),
            PayrollRegisters::uploads(['perquisites', 'outside_income']),
            Action::make('perquisite')->label('Record a perquisite')->icon(Heroicon::OutlinedGiftTop)
                ->schema([
                    $employee,
                    Select::make('financial_year')->label('Financial year')->options($years)->default($fy->label(now()))->required(),
                    Select::make('perquisite_type')->label('Type')->options(fn () => (array) config('peopleos.compliance.perquisite_types'))->searchable()->required(),
                    TextInput::make('description')->required()->maxLength(255),
                    TextInput::make('value')->label('Value for the year')->numeric()->minValue(0.01)->required(),
                    TextInput::make('recovered')->label('Recovered from the employee')->numeric()->minValue(0)->default(0),
                    TextInput::make('reference')->maxLength(120),
                ])
                ->action($run(fn (array $d) => app(TaxIncomeEntries::class)->recordPerquisite(Employee::query()->findOrFail($d['employee_id']), $d['financial_year'], $d['perquisite_type'], $d['description'], (float) $d['value'], (float) ($d['recovered'] ?? 0), auth()->user(), $d['reference'] ?? null), 'Perquisite recorded')),
            Action::make('outside')->label('Record income paid outside payroll')->icon(Heroicon::OutlinedBanknotes)->color('gray')
                ->modalDescription('A payment made outside the payroll run, or salary paid on another system earlier in the year (going live mid-year).')
                ->schema([Grid::make(2)->schema([
                    $employee,
                    DatePicker::make('paid_on')->label('Paid on')->native(false)->required(),
                    TextInput::make('description')->required()->maxLength(255),
                    TextInput::make('amount')->label('Amount paid')->numeric()->minValue(0.01)->required(),
                    TextInput::make('taxable')->label('Taxable part')->numeric()->minValue(0)->required(),
                    TextInput::make('tds')->label('Income tax deducted on it')->numeric()->minValue(0)->default(0),
                    TextInput::make('pf')->label('Employee PF deducted on it')->numeric()->minValue(0)->default(0),
                    TextInput::make('pt')->label('Professional tax deducted on it')->numeric()->minValue(0)->default(0),
                    TextInput::make('reference')->maxLength(120),
                ])])
                ->action($run(fn (array $d) => app(TaxIncomeEntries::class)->recordOutside(Employee::query()->findOrFail($d['employee_id']), $d['paid_on'], $d['description'], (float) $d['amount'], (float) $d['taxable'], (float) ($d['tds'] ?? 0), (float) ($d['pf'] ?? 0), (float) ($d['pt'] ?? 0), auth()->user(), $d['reference'] ?? null), 'Income recorded')),
        ];
    }
}
