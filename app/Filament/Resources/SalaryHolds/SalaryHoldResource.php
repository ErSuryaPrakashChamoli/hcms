<?php

namespace App\Filament\Resources\SalaryHolds;

use App\Domain\Payroll\Models\SalaryHold;
use App\Domain\Payroll\Services\SalaryHolds;
use App\Filament\Resources\SalaryHolds\Pages\ListSalaryHolds;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PayrollRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/** HROne parity PY-06: salaries held out of a month's bank file, and their release. */
class SalaryHoldResource extends Resource
{
    protected static ?string $model = SalaryHold::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPauseCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Salary holds';

    protected static ?int $navigationSort = 72;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('payroll.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'holder', 'releaser']))
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (SalaryHold $r) => $r->employee?->employee_code),
                TextColumn::make('pay_month')->label('Month')->sortable(),
                TextColumn::make('reason')->wrap()->limit(60),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'held' ? 'warning' : 'success'),
                TextColumn::make('holder.name')->label('Held by')->placeholder('—'),
                TextColumn::make('released_at')->dateTime()->placeholder('—'),
                TextColumn::make('release_reference')->label('Paid with')->placeholder('Bank file')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(SalaryHold::STATUSES)->default('held'),
                // HROne parity PO-1: pay month, pay group and the WO-2 employee filters.
                PayrollRegisters::payMonth('payroll.manage'),
                PayrollRegisters::structure('payroll.manage'),
                ...PayrollRegisters::filters('payroll.manage'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('release')->icon('heroicon-m-play-circle')->color('success')->visible(fn (SalaryHold $r) => $r->status === 'held')
                    ->schema([
                        Textarea::make('note')->required()->maxLength(500),
                        TextInput::make('reference')->label('Payment reference (if paid outside the bank file)')->maxLength(120),
                    ])
                    ->action(function (SalaryHold $record, array $data) {
                        try {
                            app(SalaryHolds::class)->release($record, $data['note'], $data['reference'] ?: null);
                            Notification::make()->success()->title('Salary released')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSalaryHolds::route('/')];
    }
}
