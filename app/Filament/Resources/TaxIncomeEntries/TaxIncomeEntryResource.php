<?php

namespace App\Filament\Resources\TaxIncomeEntries;

use App\Domain\Compliance\Models\TaxIncomeEntry;
use App\Domain\Compliance\Services\TaxIncomeEntries;
use App\Filament\Resources\TaxIncomeEntries\Pages\ListTaxIncomeEntries;
use App\Filament\Support\PayrollRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/** HROne parity PY-07, PY-17, PY-18, PY-25: perquisites and income paid outside payroll, counted in TDS. */
class TaxIncomeEntryResource extends Resource
{
    protected static ?string $model = TaxIncomeEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGiftTop;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Perquisites and other income';

    protected static ?string $modelLabel = 'income entry';

    protected static ?int $navigationSort = 74;

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
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'creator']))
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name'])->description(fn (TaxIncomeEntry $r) => $r->employee?->employee_code),
                TextColumn::make('financial_year')->label('FY')->sortable(),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (TaxIncomeEntry $r) => $r->typeLabel())->color(fn (string $state) => $state === 'perquisite' ? 'info' : 'gray')->wrap(),
                TextColumn::make('description')->wrap()->limit(60),
                TextColumn::make('paid_on')->date()->placeholder('—'),
                TextColumn::make('amount')->label('Value / paid')->numeric(2),
                TextColumn::make('recovered')->numeric(2)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('taxable_amount')->label('Taxable')->numeric(2),
                TextColumn::make('tds_deducted')->label('TDS deducted')->numeric(2),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('kind')->options(TaxIncomeEntry::KINDS),
                SelectFilter::make('status')->options(TaxIncomeEntry::STATUSES)->default('active'),
                // HROne parity PO-1: the WO-2 employee filters.
                ...PayrollRegisters::filters('payroll.manage'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('cancel')->icon('heroicon-m-x-mark')->color('danger')->visible(fn (TaxIncomeEntry $r) => $r->status === 'active')
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(function (TaxIncomeEntry $record, array $data) {
                        try {
                            app(TaxIncomeEntries::class)->cancel($record, $data['reason'], auth()->user());
                            Notification::make()->success()->title('Entry cancelled')->body('The next payroll calculation leaves it out of TDS.')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not cancelled')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTaxIncomeEntries::route('/')];
    }
}
