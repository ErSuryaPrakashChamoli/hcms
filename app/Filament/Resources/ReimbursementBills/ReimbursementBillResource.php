<?php

namespace App\Filament\Resources\ReimbursementBills;

use App\Domain\Claims\Models\ReimbursementBill;
use App\Domain\Claims\Services\ReimbursementBills;
use App\Filament\Resources\ReimbursementBills\Pages\ListReimbursementBills;
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

/**
 * Data import DI-4 (decision): bills behind reimbursements paid with salary every month (pay by default). From the
 * organisation's bills date, salary TDS treats what such a reimbursement pays in the year beyond its bills as taxable.
 */
class ReimbursementBillResource extends Resource
{
    protected static ?string $model = ReimbursementBill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Reimbursement bills';

    protected static ?string $modelLabel = 'reimbursement bill';

    protected static ?int $navigationSort = 73;

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
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'component']))
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name'])->description(fn (ReimbursementBill $r) => $r->employee?->employee_code),
                TextColumn::make('component.name')->label('Reimbursement'),
                TextColumn::make('financial_year')->label('FY')->formatStateUsing(fn ($state) => $state.'-'.substr((string) ((int) $state + 1), 2))->sortable(),
                TextColumn::make('bill_date')->date()->sortable(),
                TextColumn::make('bill_number')->label('Bill no.')->placeholder('—'),
                TextColumn::make('vendor')->placeholder('—')->toggleable(),
                TextColumn::make('amount')->numeric(2),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->options(ReimbursementBill::STATUSES)->default('active'),
                // HROne parity PO-1: the WO-2 employee filters.
                ...PayrollRegisters::filters('payroll.manage'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('cancel')->icon('heroicon-m-x-mark')->color('danger')->visible(fn (ReimbursementBill $r) => $r->status === 'active')
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(function (ReimbursementBill $record, array $data) {
                        try {
                            app(ReimbursementBills::class)->cancel($record, $data['reason'], auth()->user());
                            Notification::make()->success()->title('Bill cancelled')->body('The next payroll calculation leaves it out of TDS.')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not cancelled')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReimbursementBills::route('/')];
    }
}
