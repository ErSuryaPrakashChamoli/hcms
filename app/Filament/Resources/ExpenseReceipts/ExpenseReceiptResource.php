<?php

namespace App\Filament\Resources\ExpenseReceipts;

use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\ExpenseReceipts\Pages\ListExpenseReceipts;
use App\Filament\Resources\ExpenseReceipts\Pages\ViewExpenseReceipt;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Resources\TravelRequests\TravelRequestResource;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\ExpenseRegisters;
use App\Http\Controllers\ParityFileController;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity EX-C: expense receipts. Employees see and file their own (Me → My expenses); people who see
 * expenses in scope (`expense.view`, `expense.approve`, `expense.pay`) see the register under Expense & travel.
 * Undecided expenses can be changed (every rule checked again) or withdrawn; reports are built on Expense reports.
 * HROne parity XO-2: a read-only receipt page (every captured field, custom fields, tax lines, mileage, the checks with
 * the receipts the duplicate check matched, report, trip, payment status of the report, files, history) for whoever may
 * view the receipt, and the register's invoice / reimbursable / updated columns and type, checks, payment status and
 * date filters (HR side).
 */
class ExpenseReceiptResource extends Resource
{
    protected static ?string $model = ExpenseReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $modelLabel = 'expense';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    /** People who see expenses in scope get the register; everyone else only their own. */
    public static function oversees(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('expense.view') || $user->can('expense.approve') || $user->can('expense.pay'));
    }

    public static function getNavigationGroup(): ?string
    {
        return static::oversees() ? 'Expense & travel' : 'Me';
    }

    public static function getNavigationLabel(): string
    {
        return static::oversees() ? 'Expense receipts' : 'My expenses';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'category', 'report']);

        return static::oversees() ? $query : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                // HROne parity XO-2 (WO-2): the employee by code, names, work e-mail or designation.
                TextColumn::make('employee.person.display_name')->label('Employee')->visible(fn () => static::oversees())
                    ->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('expense_date')->date()->sortable(),
                TextColumn::make('category.name')->label('Category')->badge()->color('gray'),
                TextColumn::make('amount')->label('Amount')->state(fn (ExpenseReceipt $r) => $r->type === 'mileage' ? number_format((float) $r->distance_km, 2).' km · '.number_format((float) $r->amount, 2) : $r->currency.' '.number_format((float) $r->amount, 2)),
                TextColumn::make('claimable_amount')->label('Claimable')->numeric(2),
                TextColumn::make('approved_amount')->label('Approved')->numeric(2)->placeholder('—'),
                TextColumn::make('flags')->label('Checks')->badge()->color('warning')->placeholder('—')
                    ->state(fn (ExpenseReceipt $r) => [...($r->is_exceptional ? ['Exceptional'] : []), ...array_map(fn (string $f) => (string) config("peopleos.expense.receipt_flags.{$f}", $f), (array) $r->flags)]),
                TextColumn::make('report.number')->label('Report')->placeholder('—'),
                TextColumn::make('files')->label('Files')->html()->state(fn (ExpenseReceipt $r) => new HtmlString(collect($r->attachments ?? [])->values()
                    ->map(fn (string $path, int $i) => '<a class="text-primary-600 underline" target="_blank" href="'.e(ParityFileController::url('expense-receipt', $r, $i)).'">'.e(basename($path)).'</a>')->implode('<br>')))->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ExpenseReceipt::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => self::statusColor($state)),
                // HROne parity XO-2 (matrix 26 §26.4.1 #6, #8, #10): invoice, reimbursable, updated — for the HR side,
                // hidden until chosen.
                TextColumn::make('invoice_number')->label('Invoice number')->placeholder('—')->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('reimbursable')->label('Repaid to the employee')->boolean()->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Updated on')->dateTime()->sortable()->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment')->label('Payment')->state(fn (ExpenseReceipt $r) => ExpenseRegisters::receiptPayment($r))->placeholder('—')
                    ->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ExpenseReceipt::STATUSES)->multiple(),
                SelectFilter::make('expense_category_id')->label('Category')->options(fn () => ExpenseCategory::query()->orderBy('name')->pluck('name', 'id')),
                TernaryFilter::make('is_exceptional')->label('Exceptional'),
                // HROne parity XO-2 (§26.4.1 #18–#21): expense type, checks, the report's payment status and the expense
                // date range, with the WO-2 employee filters — for the HR side only.
                SelectFilter::make('type')->label('Expense type')->options(fn () => (array) config('peopleos.expense.types'))->visible(fn () => static::oversees()),
                ExpenseRegisters::checks(fn () => static::oversees()),
                ExpenseRegisters::paymentStatus(fn () => static::oversees()),
                ...ExpenseRegisters::filters(fn () => static::oversees(), ['expense_dates' => ['Expense date', 'expense_date']]),
            ])
            ->defaultSort('expense_date', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('change')->label('Change')->icon('heroicon-m-pencil-square')->color('gray')
                    ->visible(fn (ExpenseReceipt $r) => ($r->status === 'unreported' || ($r->status === 'reported' && $r->report?->isEditable())) && auth()->user()->can('update', $r))
                    ->fillForm(fn (ExpenseReceipt $r) => ExpenseReceiptForm::fromReceipt($r))
                    ->schema(fn (ExpenseReceipt $record) => ExpenseReceiptForm::schema(fn (Get $get) => Employee::query()->find($record->employee_id)))
                    ->action(function (ExpenseReceipt $record, array $data) {
                        try {
                            app(ExpenseReceipts::class)->update($record, ExpenseReceiptForm::toData($data));
                            Notification::make()->success()->title('Expense changed')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('withdraw')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                    ->visible(fn (ExpenseReceipt $r) => $r->status === 'unreported' && auth()->user()->can('update', $r))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(function (ExpenseReceipt $record, array $data) {
                        try {
                            app(ExpenseReceipts::class)->withdraw($record, $data['reason']);
                            Notification::make()->success()->title('Expense withdrawn')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not withdrawn')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'approved' => 'success', 'partly_approved', 'reported' => 'info', 'rejected', 'expired', 'withdrawn' => 'gray', default => 'warning',
        };
    }

    /**
     * HROne parity XO-2 (matrix 26 §26.4.1 #9, #12–#14, #17; §26.4.3 #14, #16): the receipt in full, read-only. Links to the
     * report, the trip and the matched receipts appear only where the viewer may open them; files go through the signed,
     * audited file route; the payment of the report shows as on the report page (the employee only when the organisation
     * shows it).
     */
    public static function infolist(Schema $schema): Schema
    {
        $money = fn (ExpenseReceipt $r, mixed $value) => $value === null ? null : $r->currency.' '.number_format((float) $value, 2);
        $base = fn () => app(CurrencyRates::class)->baseCurrency();
        $mileage = fn (ExpenseReceipt $r) => $r->type === ExpenseCategory::MILEAGE;
        $link = fn (?string $url, string $text) => $url === null ? $text : new HtmlString('<a class="text-primary-600 underline" href="'.e($url).'">'.e($text).'</a>');

        return $schema->components([
            Section::make('Expense')->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('employee.person.display_name')->label('Employee'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => ExpenseReceipt::STATUSES[$state] ?? $state)->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('type')->label('Expense type')->formatStateUsing(fn (?string $state) => (string) config("peopleos.expense.types.{$state}", (string) $state)),
                TextEntry::make('category.name')->label('Category'),
                TextEntry::make('category_group')->label('Expense group')->state(fn (ExpenseReceipt $r) => $r->category?->group?->name)->placeholder('—'),
                TextEntry::make('expense_date')->label('Expense date')->date(),
                TextEntry::make('expires_on')->label('Expires on')->date()->placeholder('—'),
                TextEntry::make('created_at')->label('Filed on')->dateTime(),
                TextEntry::make('filed_by')->label('Filed by')->state(fn (ExpenseReceipt $r) => $r->creator?->name)->placeholder('—'),
                TextEntry::make('updated_at')->label('Updated on')->dateTime(),
                TextEntry::make('declaration_accepted_at')->label('Declaration accepted')->dateTime()->visible(fn (ExpenseReceipt $r) => $r->declaration_accepted_at !== null),
            ]),
            Section::make('Amount')->columns(4)->schema([
                TextEntry::make('amount')->label('Amount')->state(fn (ExpenseReceipt $r) => $money($r, $r->amount)),
                TextEntry::make('exchange_rate')->label('Exchange rate')->numeric(4)->visible(fn (ExpenseReceipt $r) => $r->currency !== $base()),
                TextEntry::make('base_amount')->label('Amount (base currency)')->money($base),
                TextEntry::make('limit_amount')->label('Limit when filed')->numeric(2)->placeholder('No limit'),
                TextEntry::make('over_limit_amount')->label('Over the limit')->numeric(2),
                TextEntry::make('claimable_amount')->label('Claimable')->numeric(2),
                TextEntry::make('approved_amount')->label('Approved')->numeric(2)->placeholder('Not decided'),
                IconEntry::make('reimbursable')->label('Repaid to the employee')->boolean(),
                IconEntry::make('is_exceptional')->label('Exceptional')->boolean(),
                TextEntry::make('exception_reason')->label('Reason it is exceptional')->columnSpanFull()->visible(fn (ExpenseReceipt $r) => filled($r->exception_reason)),
                TextEntry::make('approver_note')->label('Approver note')->columnSpanFull()->placeholder('—'),
            ]),
            Section::make('Mileage')->columns(4)->visible($mileage)->schema([
                TextEntry::make('from_place')->label('From')->placeholder('—'),
                TextEntry::make('to_place')->label('To')->placeholder('—'),
                TextEntry::make('worked_minutes')->label('Hours worked')->formatStateUsing(fn (?int $state) => $state === null ? null : intdiv($state, 60).' h '.str_pad((string) ($state % 60), 2, '0', STR_PAD_LEFT).' min')->placeholder('—'),
                TextEntry::make('distance_km')->label('Distance (km)')->numeric(2),
                TextEntry::make('computed_distance_km')->label('Distance measured (km)')->numeric(2)->placeholder('—')
                    ->suffix(fn (ExpenseReceipt $r) => $r->distance_source !== null && $r->distance_source !== 'manual' ? ' (from the '.$r->distance_source.')' : null),
                TextEntry::make('rate_per_km')->label('Rate per km')->state(fn (ExpenseReceipt $r) => (float) $r->distance_km > 0 ? round((float) $r->base_amount / (float) $r->distance_km, 2) : null)->numeric(2)->placeholder('—'),
                TextEntry::make('odometer_start')->label('Odometer at the start')->numeric(1)->placeholder('—'),
                TextEntry::make('odometer_end')->label('Odometer at the end')->numeric(1)->placeholder('—'),
            ]),
            Section::make('Details')->columns(3)->schema([
                TextEntry::make('purpose')->placeholder('—'),
                TextEntry::make('merchant')->placeholder('—'),
                TextEntry::make('invoice_number')->label('Invoice number')->placeholder('—'),
                TextEntry::make('participants_list')->label('Participants')->state(fn (ExpenseReceipt $r) => implode(', ', (array) $r->participants) ?: null)->placeholder('—'),
                TextEntry::make('location')->label('Where')->state(fn (ExpenseReceipt $r) => implode(', ', array_filter([$r->location_city, $r->location_state])) ?: null)->placeholder('—'),
                TextEntry::make('location_group')->label('Location group')->state(fn (ExpenseReceipt $r) => $r->locationGroup?->name)->placeholder('—'),
                TextEntry::make('trip')->label('Travel request')->html()->placeholder('—')
                    ->state(fn (ExpenseReceipt $r) => ($trip = $r->travelRequest) !== null && auth()->user()?->can('view', $trip)
                        ? $link(TravelRequestResource::getUrl('view', ['record' => $trip]), $trip->number.' · '.$trip->start_date->format('j M').'–'.$trip->end_date->format('j M Y'))
                        : null),
                TextEntry::make('description')->columnSpanFull()->placeholder('—'),
            ]),
            Section::make('Tax')->columns(3)->visible(fn (ExpenseReceipt $r) => filled($r->supplier_gstin) || $r->taxes()->exists())->schema([
                TextEntry::make('supplier_gstin')->label('Supplier GST registration number')->placeholder('—'),
                TextEntry::make('gst_supply_type')->label('Supply of')->formatStateUsing(fn (?string $state) => (string) config("peopleos.expense.gst_supply_types.{$state}", (string) $state))->placeholder('—'),
                RepeatableEntry::make('tax_lines')->label('Tax lines')->columnSpanFull()->columns(3)
                    ->state(fn (ExpenseReceipt $r) => $r->taxes()->with('taxType')->get()
                        ->map(fn ($tax) => ['tax' => $tax->taxType?->name ?? '—', 'rate' => $tax->rate !== null ? number_format((float) $tax->rate, 2).'%' : '—', 'amount' => $money($r, $tax->amount)])->all())
                    ->schema([TextEntry::make('tax')->label('Tax'), TextEntry::make('rate')->label('Rate'), TextEntry::make('amount')->label('Amount')]),
            ]),
            Section::make('Checks')->columns(3)->schema([
                TextEntry::make('checks')->label('Checks')->badge()->color('warning')->placeholder('None')
                    ->state(fn (ExpenseReceipt $r) => [...($r->is_exceptional ? ['Exceptional'] : []), ...array_map(fn (string $f) => ExpenseRegisters::checkLabel($f), (array) $r->flags)]),
                TextEntry::make('duplicate_score')->label('Closest match')->suffix('%')->placeholder('—'),
                TextEntry::make('matched_receipts')->label('Receipts it resembles')->html()->columnSpanFull()->placeholder('—')
                    ->state(fn (ExpenseReceipt $r) => ExpenseRegisters::matchesHtml($r))
                    ->visible(fn (ExpenseReceipt $r) => ExpenseRegisters::hasMatches($r)),
            ]),
            Section::make('Report and payment')->columns(3)->schema([
                TextEntry::make('report_link')->label('Expense report')->html()->placeholder('Not in a report')
                    ->state(fn (ExpenseReceipt $r) => ($report = $r->report) === null ? null
                        : (auth()->user()?->can('view', $report) ? $link(ExpenseReportResource::getUrl('view', ['record' => $report]), $report->number) : $report->number)),
                TextEntry::make('report_status')->label('Report status')->placeholder('—')
                    ->state(fn (ExpenseReceipt $r) => $r->report !== null ? (ExpenseReport::STATUSES[$r->report->status] ?? $r->report->status) : null),
                TextEntry::make('report_payment')->label('Payment')->placeholder('—')
                    ->state(fn (ExpenseReceipt $r) => $r->report !== null && ExpenseReportResource::showsPayment($r->report) ? $r->report->paymentStatus() : null),
            ]),
            Section::make('Files')->schema([
                TextEntry::make('files')->hiddenLabel()->html()->placeholder('None attached')
                    ->state(fn (ExpenseReceipt $r) => ($paths = array_values(array_filter((array) ($r->attachments ?? []), fn ($p) => is_string($p) && $p !== ''))) === [] ? null
                        : new HtmlString(collect($paths)->map(fn (string $path, int $i) => '<a class="text-primary-600 underline" target="_blank" href="'.e(ParityFileController::url('expense-receipt', $r, $i)).'">'.e(basename($path)).'</a>')->implode('<br>'))),
            ]),
            CustomFieldsSchema::infolistSection(ExpenseReceipt::class),
        ]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListExpenseReceipts::route('/'), 'view' => ViewExpenseReceipt::route('/{record}')];
    }
}
