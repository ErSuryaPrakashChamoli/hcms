<?php

namespace App\Filament\Pages;

use App\Domain\Compliance\Models\StatutoryChallan;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Compliance\Services\Returns\EsiReturns;
use App\Domain\Compliance\Services\Returns\LwfReturns;
use App\Domain\Compliance\Services\Returns\ProfessionalTaxReturns;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Domain\Compliance\Services\StatutoryChallans;
use App\Domain\Compliance\Services\Tds\TdsLedgers;
use App\Domain\Compliance\Services\Tds\TdsQuarterlyReturns;
use App\Domain\ComplianceReports\Services\ComplianceReports;
use App\Domain\ComplianceReports\Support\ReportCatalogue;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Http\Controllers\ParityFileController;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * HROne parity PO-13 (matrix 25 §25.6.12, HROne's "Generate challan" / `challan_124`): one financial year × month grid per
 * statute — tabs Provident fund, ESI, Professional tax, Labour welfare fund, Income tax — over the statutory returns and the
 * challan register. Each cell is not generated / generated / approved / paid (a challan recorded) / filed. A cell opens its
 * panel: the return (open it, generate or regenerate it from finalized payroll, download its exported file), the challan
 * working sheet from Compliance reports, the challans recorded (view, verify, cancel, receipt) and "Record payment" — the
 * challan as a step wizard (for income tax the employees whose TDS it deposits, read from finalized payroll).
 *
 * Opens with compliance.returns.view; recording, verifying and cancelling need compliance.returns.file (never the return's
 * approver; never verifying one's own); generating needs compliance.returns.generate. The services check again. Launch
 * "Record payment" directly with `?action=recordPayment` (optionally `&actionArguments[statute]=tds&actionArguments[unit]=…
 * &actionArguments[month]=2026-09`).
 */
class StatutoryChallansPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Challans';

    protected static ?string $title = 'Challans';

    protected static ?string $slug = 'statutory-challans';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.statutory-challans';

    public const TABS = ['pf' => 'Provident fund', 'esi' => 'Employee state insurance', 'pt' => 'Professional tax', 'lwf' => 'Labour welfare fund', 'tds' => 'Income tax'];

    /** The monthly return generators behind "Generate" (the same services the return registers use). */
    private const GENERATORS = ['pf' => EpfReturns::class, 'esi' => EsiReturns::class, 'pt' => ProfessionalTaxReturns::class, 'lwf' => LwfReturns::class];

    /** The compliance report that is each statute's challan working sheet. */
    private const WORKING_SHEETS = ['pf' => 'pf_challan', 'esi' => 'esi_challan', 'tds' => 'tds_deposit', 'pt' => 'pt_statement', 'lwf' => 'lwf_statement'];

    #[Url]
    public string $statute = 'pf';

    #[Url]
    public string $year = '';

    /** The open cell: "{unit id}|YYYY-MM". */
    #[Url]
    public ?string $cell = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $gridMemo = null;

    /** @var array<string, bool> deductor-years whose TDS ledger was brought up to date in this request */
    private array $synced = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ! $user->isPlatformAdmin() && $user->hasPermission('compliance.returns.view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->normalise();
        $this->syncTds();
    }

    public function getSubheading(): ?string
    {
        return 'Each statute\'s challans by month for the financial year: generate the return from finalized payroll, download the challan file or working sheet, and record the payment as made (challan no., date, mode, bank, amount, interest, penalty, receipt). Recording a payment files nothing: returns are approved and filed on their own pages.';
    }

    public function setStatute(string $statute): void
    {
        $this->statute = $statute;
        $this->cell = null;
        $this->normalise();
        $this->syncTds();
    }

    public function updatedYear(): void
    {
        $this->cell = null;
        $this->normalise();
        $this->syncTds();
    }

    public function selectCell(int $unit, string $month): void
    {
        $this->cell = $unit.'|'.$month;
    }

    public function closeCell(): void
    {
        $this->cell = null;
    }

    public function service(): StatutoryChallans
    {
        return app(StatutoryChallans::class);
    }

    /** @return list<array<string, mixed>> */
    public function grid(): array
    {
        return $this->gridMemo ??= $this->service()->grid($this->statute, $this->year);
    }

    /** @return list<CarbonImmutable> */
    public function months(): array
    {
        return $this->service()->months($this->year);
    }

    /** @return array<string, string> */
    public function yearOptions(): array
    {
        return $this->service()->yearOptions();
    }

    /** @return array{locations: list<array<string, mixed>>, state_missing: int} */
    public function coverage(): array
    {
        return $this->service()->coverage($this->year);
    }

    /** @return array{unit: Establishment|LegalEntity, month: CarbonImmutable, cell: array<string, mixed>}|null */
    public function selected(): ?array
    {
        if ($this->cell === null || ! str_contains($this->cell, '|')) {
            return null;
        }
        [$unitId, $month] = explode('|', $this->cell, 2);
        $unit = $this->service()->unit($this->statute, $unitId);
        try {
            $month = $this->service()->month($month);
        } catch (RuntimeException) {
            return null;
        }

        return $unit === null ? null : ['unit' => $unit, 'month' => $month, 'cell' => $this->service()->cell($this->statute, $unit, $month)];
    }

    public function stateLabel(string $state): string
    {
        return StatutoryChallans::STATES[$state] ?? $state;
    }

    public function stateColor(string $state): string
    {
        return StatutoryChallans::STATE_COLORS[$state] ?? 'gray';
    }

    public function returnUrl(?StatutoryReturn $return): ?string
    {
        $resource = $return !== null ? (ComplianceControlRoom::RESOURCES[$return->return_type] ?? null) : null;

        return $resource !== null && $this->user()->can('view', $return) ? $resource::getUrl('view', ['record' => $return]) : null;
    }

    public function receiptUrl(StatutoryChallan $challan): ?string
    {
        return filled($challan->getAttributes()['receipt_path'] ?? null) ? ParityFileController::url('statutory-challan', $challan, 0) : null;
    }

    public function canFile(): bool
    {
        return $this->user()->hasPermission('compliance.returns.file');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->recordPaymentAction(),
            Action::make('complianceReports')->label('Compliance reports')->icon(Heroicon::OutlinedDocumentChartBar)->color('gray')
                ->visible(fn () => ComplianceReportsPage::canAccess())->url(fn () => ComplianceReportsPage::getUrl()),
            Action::make('controlRoom')->label('Compliance control room')->icon(Heroicon::OutlinedShieldCheck)->color('gray')
                ->visible(fn () => ComplianceControlRoom::canAccess())->url(fn () => ComplianceControlRoom::getUrl()),
        ];
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Record payment (the challan wizard)
    // ---------------------------------------------------------------------------------------------------------------------

    public function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')->label('Record payment')->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn () => $this->canFile())
            ->modalHeading('Record a challan payment')
            ->modalDescription('The payment as made on the portal or at the bank. You cannot record the payment of a return you approved.')
            ->fillForm(function (array $arguments) {
                $statute = array_key_exists($arguments['statute'] ?? '', self::TABS) ? $arguments['statute'] : $this->statute;
                $month = is_string($arguments['month'] ?? null) ? $arguments['month'] : now()->subMonthNoOverflow()->format('Y-m');
                $unit = isset($arguments['unit']) && $this->service()->unit($statute, $arguments['unit']) !== null ? (int) $arguments['unit'] : null;
                $this->syncTds($statute, $unit, $month);

                return [
                    'statute' => $statute, 'unit_id' => $unit, 'month' => $month, 'payment_mode' => 'net_banking', 'interest' => 0, 'penalty' => 0,
                    'nature_of_payment' => (string) config('peopleos.compliance.formats.TDS_FORM_138.section_code'),
                    'employee_ids' => $statute === 'tds' && $unit !== null ? array_keys($this->allocationOptions($statute, $unit, $month)) : [],
                ];
            })
            ->steps([
                Step::make('Challan for')->schema([
                    Select::make('statute')->label('Statute')->options(self::TABS)->required()->native(false)->live()->selectablePlaceholder(false)
                        ->afterStateUpdated(function (Set $set) {
                            $set('unit_id', null);
                            $set('employee_ids', []);
                        }),
                    Select::make('unit_id')->label(fn (Get $get) => $get('statute') === 'tds' ? 'Legal entity (deductor, TAN)' : 'Establishment')->required()->native(false)->live()
                        ->options(fn (Get $get) => $this->unitOptions((string) $get('statute')))
                        ->afterStateUpdated(fn (Get $get, Set $set) => $this->refreshAllocation($get, $set)),
                    Select::make('month')->label('Month the challan pays')->required()->native(false)->live()->options(fn () => $this->monthOptions())
                        ->helperText('For income tax, the month whose salaries the TDS was deducted from (by payment date).')
                        ->afterStateUpdated(fn (Get $get, Set $set) => $this->refreshAllocation($get, $set)),
                    Text::make(fn (Get $get) => $this->returnHint((string) $get('statute'), $get('unit_id'), $get('month')))->columnSpanFull(),
                ]),
                Step::make('Employees')->visible(fn (Get $get) => $get('statute') === 'tds')->schema([
                    Text::make('Each employee\'s TDS as finalized payroll deducted it, less what other challans of the month already deposited. The amounts are never edited here: a difference is corrected by a TDS override in an open payroll month.')->columnSpanFull(),
                    CheckboxList::make('employee_ids')->label('Deposit the TDS of')->required(fn (Get $get) => $get('statute') === 'tds')->bulkToggleable()->live()
                        ->options(fn (Get $get) => $this->allocationOptions((string) $get('statute'), $get('unit_id'), $get('month')))
                        ->descriptions(fn (Get $get) => $this->allocationDescriptions((string) $get('statute'), $get('unit_id'), $get('month'))),
                    Text::make(fn (Get $get) => $this->allocationSummary((string) $get('statute'), $get('unit_id'), $get('month'), (array) ($get('employee_ids') ?? [])))->columnSpanFull(),
                ]),
                Step::make('Payment')->columns(2)->schema([
                    TextInput::make('alias')->label('Challan alias (optional)')->maxLength(100),
                    TextInput::make('challan_number')->label(fn (Get $get) => StatutoryChallans::numberLabel((string) $get('statute')))->required()->maxLength(64),
                    TextInput::make('acknowledgement_number')->label('Acknowledgement no. / CRN / CIN (optional)')->maxLength(64),
                    TextInput::make('bsr_code')->label('BSR code')->visible(fn (Get $get) => $get('statute') === 'tds')->required(fn (Get $get) => $get('statute') === 'tds')
                        ->length(7)->regex('/^\d{7}$/')->helperText('The 7-digit code of the bank branch where the tax was deposited.'),
                    TextInput::make('nature_of_payment')->label('Nature of payment (section)')->visible(fn (Get $get) => $get('statute') === 'tds')->maxLength(16),
                    DatePicker::make('paid_on')->label('Challan date (paid on)')->required()->native(false)->maxDate(now()),
                    Select::make('payment_mode')->label('Payment mode')->options((array) config('peopleos.compliance.challan_payment_modes', []))->required()->native(false),
                    TextInput::make('bank_name')->label('Bank (optional)')->maxLength(120),
                    TextInput::make('branch')->label('Branch (optional)')->maxLength(120),
                    TextInput::make('amount')->label('Amount paid (contribution / tax)')->numeric()->minValue(0.01)
                        ->visible(fn (Get $get) => $get('statute') !== 'tds')->required(fn (Get $get) => $get('statute') !== 'tds')
                        ->helperText(fn (Get $get) => $this->amountHint((string) $get('statute'), $get('unit_id'), $get('month'))),
                    TextInput::make('interest')->label('Interest paid')->numeric()->minValue(0)->default(0),
                    TextInput::make('penalty')->label('Penalty / damages paid')->numeric()->minValue(0)->default(0),
                    FileUpload::make('receipt')->label('Receipt (optional)')->disk(config('peopleos.documents.disk', 'local'))->directory('statutory-challans')->visibility('private')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize((int) config('peopleos.documents.max_kb', 10240))->storeFileNamesIn('receipt_name')->columnSpanFull(),
                    Textarea::make('notes')->label('Notes (optional)')->rows(2)->maxLength(2000)->columnSpanFull(),
                ]),
                Step::make('Review')->schema([
                    Text::make(fn (Get $get) => $this->reviewText($get))->columnSpanFull(),
                ]),
            ])
            ->action(function (array $data, Action $action) {
                try {
                    $challan = $this->service()->record($data, $this->user());
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                }
                $this->gridMemo = null;
                $this->statute = $challan->statute;
                $this->cell = ($challan->establishment_id ?? $challan->legal_entity_id).'|'.$challan->period_month->format('Y-m');
                Notification::make()->success()->title('Challan recorded')
                    ->body($challan->label().' · '.number_format($challan->total(), 2).' paid on '.$challan->paid_on->format('d M Y').'.')->send();
            });
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Cell actions
    // ---------------------------------------------------------------------------------------------------------------------

    /** "Generate" / "Regenerate" the return of the cell from finalized payroll (the return registers' own services). */
    public function generateReturnAction(): Action
    {
        return Action::make('generateReturn')->label(fn (array $arguments) => $this->cellReturn($arguments) !== null ? 'Regenerate return' : 'Generate return')
            ->icon(Heroicon::OutlinedArrowPath)->color('gray')->size('sm')->requiresConfirmation()
            ->modalDescription(fn (array $arguments) => ($this->statuteOf($arguments) === 'tds' ? 'Builds the quarter\'s Form No. 138 statement from the TDS ledger (finalized payroll) with the challans recorded here.' : 'Builds the month\'s return from finalized payroll.')
                .' Validate it, have someone else approve it, then export and file it on the return\'s page.')
            ->visible(function (array $arguments) {
                $return = $this->cellReturn($arguments);

                return $this->user()->hasPermission('compliance.returns.generate') && ($return === null || $return->isEditable());
            })
            ->action(function (array $arguments) {
                try {
                    $statute = $this->statuteOf($arguments);
                    $unit = $this->service()->unit($statute, $arguments['unit'] ?? null) ?? throw new RuntimeException('That establishment or legal entity was not found.');
                    $month = $this->service()->month($arguments['month'] ?? null);
                    $return = $unit instanceof LegalEntity
                        ? app(TdsQuarterlyReturns::class)->generate($unit, $this->service()->financialYearOf($month), $this->service()->quarterOf($month), $this->user())
                        : app(self::GENERATORS[$statute])->generate($unit, $month->year, $month->month, $this->user());
                    $this->gridMemo = null;
                    Notification::make()->success()->title('Return generated')->body($return->label().' is '.config("peopleos.compliance.return_statuses.{$return->status}", $return->status).'. Validate and approve it on its page.')->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not generated')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    /** "Challan file": the return's exported file (the portal upload), as exported on the return's page. */
    public function downloadReturnFileAction(): Action
    {
        return Action::make('downloadReturnFile')->label('Challan file')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')->size('sm')
            ->visible(fn (array $arguments) => ($return = $this->returnById($arguments)) !== null && $return->export_path !== null && $this->user()->hasPermission('compliance.returns.export'))
            ->action(function (array $arguments) {
                try {
                    $return = $this->returnById($arguments) ?? throw new RuntimeException('That return was not found.');
                    $content = app(StatutoryReturns::class)->exportContent($return, $this->user());

                    return response()->streamDownload(fn () => print ($content), $return->export_filename, ['Content-Type' => 'text/plain']);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    /** "Working sheet": the statute's challan working from Compliance reports, for this unit and month. */
    public function downloadWorkingSheetAction(): Action
    {
        return Action::make('downloadWorkingSheet')->label('Working sheet')->icon(Heroicon::OutlinedTableCells)->color('gray')->size('sm')
            ->visible(fn () => $this->user()->hasPermission('compliance.reports.generate'))
            ->action(function (array $arguments) {
                try {
                    $statute = $this->statuteOf($arguments);
                    $unit = $this->service()->unit($statute, $arguments['unit'] ?? null) ?? throw new RuntimeException('That establishment or legal entity was not found.');
                    $month = $this->service()->month($arguments['month'] ?? null);
                    $key = self::WORKING_SHEETS[$statute];
                    $item = match (true) {
                        $unit instanceof LegalEntity => "{$key}|legal_entity|{$unit->getKey()}",
                        in_array($statute, ['pt', 'lwf'], true) => ($state = ReportCatalogue::canonicalState($unit->state)) !== null ? "{$key}:{$state}|state|{$state}" : throw new RuntimeException($unit->name.' has no state.'),
                        default => "{$key}|establishment|{$unit->getKey()}",
                    };
                    $company = $unit->company ?? throw new RuntimeException('The company was not found.');
                    $options = $unit instanceof Establishment ? ['establishment_ids' => [$unit->getKey()]] : [];
                    $file = app(ComplianceReports::class)->single($company, ReportPeriod::month($month->year, $month->month), $item, $this->user(), false, $options + ['include_shorter' => true]);

                    return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('No working sheet')->body($e->getMessage())->send();
                }
            });
    }

    /** §25.6.12 #16: every field of the challan, who recorded and verified it, the receipt and (income tax) the employees. */
    public function viewChallanAction(): Action
    {
        return Action::make('viewChallan')->label('View')->icon(Heroicon::OutlinedEye)->color('gray')->size('sm')->link()
            ->modalHeading(fn (array $arguments) => $this->challan($arguments)?->label() ?? 'Challan')
            ->modalContent(fn (array $arguments) => ($c = $this->challan($arguments)) !== null
                ? view('filament.pages.statutory-challans.challan', ['challan' => $this->service()->present($c), 'receipt' => $this->receiptUrl($c)])
                : null)
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    public function verifyChallanAction(): Action
    {
        return Action::make('verifyChallan')->label('Verify')->icon(Heroicon::OutlinedCheckBadge)->color('success')->size('sm')->link()
            ->modalHeading('Verify the challan')
            ->modalDescription('Confirm the payment against the bank statement or the portal receipt. You cannot verify a challan you recorded.')
            ->visible(fn (array $arguments) => ($c = $this->challan($arguments)) !== null && $c->status === StatutoryChallan::RECORDED && $this->canFile() && (int) $c->recorded_by !== (int) $this->user()->getKey())
            ->schema([Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(500)])
            ->action(fn (array $arguments, array $data) => $this->attempt(fn () => $this->service()->verify($this->challan($arguments) ?? throw new RuntimeException('That challan was not found.'), $this->user(), $data['note'] ?? null), 'Challan verified'));
    }

    public function cancelChallanAction(): Action
    {
        return Action::make('cancelChallan')->label('Cancel')->icon(Heroicon::OutlinedXCircle)->color('danger')->size('sm')->link()
            ->modalHeading('Cancel the challan')
            ->modalDescription('For a challan recorded by mistake: it stays in the register as cancelled and no longer counts as paid (its TDS allocation is freed). Record the right one again.')
            ->visible(fn (array $arguments) => ($c = $this->challan($arguments)) !== null && $c->status === StatutoryChallan::RECORDED && $this->canFile())
            ->schema([Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(500)])
            ->action(fn (array $arguments, array $data) => $this->attempt(fn () => $this->service()->cancel($this->challan($arguments) ?? throw new RuntimeException('That challan was not found.'), $this->user(), (string) $data['reason']), 'Challan cancelled'));
    }

    public function attachReceiptAction(): Action
    {
        return Action::make('attachReceipt')->label(fn (array $arguments) => filled($this->challan($arguments)?->getAttributes()['receipt_path'] ?? null) ? 'Replace receipt' : 'Attach receipt')
            ->icon(Heroicon::OutlinedPaperClip)->color('gray')->size('sm')->link()
            ->visible(fn (array $arguments) => ($c = $this->challan($arguments)) !== null && $c->status === StatutoryChallan::RECORDED && $this->canFile())
            ->schema([
                FileUpload::make('receipt')->label('Receipt')->required()->disk(config('peopleos.documents.disk', 'local'))->directory('statutory-challans')->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize((int) config('peopleos.documents.max_kb', 10240))->storeFileNamesIn('receipt_name'),
            ])
            ->action(fn (array $arguments, array $data) => $this->attempt(fn () => $this->service()->attachReceipt($this->challan($arguments) ?? throw new RuntimeException('That challan was not found.'), (string) $data['receipt'], $data['receipt_name'] ?? null, $this->user()), 'Receipt attached'));
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Wizard helpers
    // ---------------------------------------------------------------------------------------------------------------------

    /** @return array<int, string> */
    public function unitOptions(string $statute): array
    {
        if (! array_key_exists($statute, self::TABS)) {
            return [];
        }

        return $this->service()->units($statute)->mapWithKeys(fn ($u) => [$u->getKey() => $this->service()->unitLabel($u)])->all();
    }

    /** @return array<string, string> the eighteen months up to this one, latest first */
    public function monthOptions(): array
    {
        $start = CarbonImmutable::now()->startOfMonth();

        return collect(range(0, 17))->mapWithKeys(fn (int $i) => [$start->subMonthsNoOverflow($i)->format('Y-m') => $start->subMonthsNoOverflow($i)->format('M Y')])->all();
    }

    /** @return array<int, string> employee id => "CODE · Name · PAN" for those with TDS left to deposit */
    public function allocationOptions(string $statute, mixed $unit, mixed $month): array
    {
        return $this->allocation($statute, $unit, $month)->filter(fn (array $r) => $r['to_allocate'] > 0)
            ->mapWithKeys(fn (array $r) => [$r['employee_id'] => "{$r['code']} · {$r['name']} · PAN {$r['pan']} · TDS ".number_format($r['to_allocate'], 2)])->all();
    }

    /** @return array<int, string> */
    public function allocationDescriptions(string $statute, mixed $unit, mixed $month): array
    {
        return $this->allocation($statute, $unit, $month)->filter(fn (array $r) => $r['to_allocate'] > 0)
            ->mapWithKeys(fn (array $r) => [$r['employee_id'] => 'Gross '.number_format($r['gross'], 2).' · deducted '.number_format($r['deducted'], 2).($r['allocated'] > 0 ? ' · already deposited '.number_format($r['allocated'], 2) : '')])->all();
    }

    public function allocationSummary(string $statute, mixed $unit, mixed $month, array $chosen): string
    {
        $rows = $this->allocation($statute, $unit, $month);
        if ($rows->isEmpty()) {
            return 'Finalized payroll deducted no TDS for this month from employees you can see.';
        }
        $chosen = array_map('intval', $chosen);
        $picked = $rows->filter(fn (array $r) => in_array($r['employee_id'], $chosen, true));

        return 'Total employees '.$rows->count().' · TDS deducted '.number_format((float) $rows->sum('deducted'), 2).' · already deposited '.number_format((float) $rows->sum('allocated'), 2)
            .' · TDS to be deposited '.number_format((float) $rows->sum('to_allocate'), 2).'. This challan: '.$picked->count().' employee(s), '.number_format((float) $picked->sum('to_allocate'), 2).'.';
    }

    public function returnHint(string $statute, mixed $unit, mixed $month): string
    {
        $return = $this->hintReturn($statute, $unit, $month);
        if ($return === false) {
            return 'Choose the statute, who paid and the month.';
        }
        if ($return === null) {
            return 'No return is generated for this '.($statute === 'tds' ? 'quarter' : 'month').' yet: the challan is recorded on its own and the grid shows it as paid.';
        }

        return 'Pays '.$return->label().' — '.config("peopleos.compliance.return_statuses.{$return->status}", $return->status).'.'
            .((int) $return->approved_by === (int) $this->user()->getKey() ? ' You approved it, so someone else must record its payment.' : '');
    }

    public function amountHint(string $statute, mixed $unit, mixed $month): ?string
    {
        $return = $this->hintReturn($statute, $unit, $month);
        if (! $return instanceof StatutoryReturn) {
            return 'As on the challan, without interest and penalty.';
        }
        $keys = (array) config("peopleos.compliance.return_types.{$return->return_type}.filing_totals", []);
        $sum = collect($return->totals ?? [])->only($keys)->sum(fn ($v) => (float) $v);

        return 'As on the challan, without interest and penalty. Contributions in '.$return->label().': '.number_format($sum, 2).' (charges such as PF admin are paid on top where they apply).';
    }

    public function reviewText(Get $get): string
    {
        $statute = (string) $get('statute');
        $unit = $this->service()->unit($statute, $get('unit_id'));
        $month = is_string($get('month')) ? CarbonImmutable::parse($get('month').'-01')->format('M Y') : '—';
        $amount = $statute === 'tds'
            ? (float) $this->allocation($statute, $get('unit_id'), $get('month'))->filter(fn (array $r) => in_array($r['employee_id'], array_map('intval', (array) ($get('employee_ids') ?? [])), true))->sum('to_allocate')
            : (float) $get('amount');
        $total = $amount + (float) $get('interest') + (float) $get('penalty');

        return 'You are recording a '.(self::TABS[$statute] ?? $statute).' challan for '.($unit !== null ? $this->service()->unitLabel($unit) : '—').', '.$month.': '
            .StatutoryChallans::numberLabel($statute).' '.($get('challan_number') ?: '—').', paid on '.($get('paid_on') ? CarbonImmutable::parse($get('paid_on'))->format('d M Y') : '—').' by '
            .(config('peopleos.compliance.challan_payment_modes.'.$get('payment_mode')) ?? '—').'. Amount '.number_format($amount, 2).' + interest '.number_format((float) $get('interest'), 2)
            .' + penalty '.number_format((float) $get('penalty'), 2).' = '.number_format($total, 2).'. It is recorded as you entered it and cannot be edited later (cancel it with a reason if it is wrong).';
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------------------------------------------------------

    private function normalise(): void
    {
        if (! array_key_exists($this->statute, self::TABS)) {
            $this->statute = 'pf';
        }
        if (! array_key_exists($this->year, $this->yearOptions())) {
            $this->year = $this->service()->financialYearOf(now()->subMonthNoOverflow());
        }
        $this->gridMemo = null;
    }

    /** Bring the TDS ledger up to date with finalized payroll (once per deductor-year and request): the grid and allocation read it. */
    private function syncTds(?string $statute = null, ?int $unit = null, ?string $month = null): void
    {
        if (($statute ?? $this->statute) !== 'tds') {
            return;
        }
        $years = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) ? [$this->service()->financialYearOf($month.'-01')] : [$this->year];
        $entities = $unit !== null ? array_filter([$this->service()->unit('tds', $unit)]) : $this->service()->units('tds')->all();
        foreach ($entities as $entity) {
            foreach ($years as $year) {
                if (! isset($this->synced[$entity->getKey().'|'.$year])) {
                    app(TdsLedgers::class)->sync($entity, $year);
                    $this->synced[$entity->getKey().'|'.$year] = true;
                }
            }
        }
        $this->gridMemo = null;
    }

    private function refreshAllocation(Get $get, Set $set): void
    {
        if ($get('statute') !== 'tds' || ! is_numeric($get('unit_id')) || ! is_string($get('month'))) {
            $set('employee_ids', []);

            return;
        }
        $this->syncTds('tds', (int) $get('unit_id'), (string) $get('month'));
        $set('employee_ids', array_keys($this->allocationOptions('tds', $get('unit_id'), $get('month'))));
    }

    private function allocation(string $statute, mixed $unit, mixed $month)
    {
        if ($statute !== 'tds' || ! is_numeric($unit) || ! is_string($month)) {
            return collect();
        }
        $entity = $this->service()->unit('tds', $unit);
        try {
            $month = $this->service()->month($month);
        } catch (RuntimeException) {
            return collect();
        }

        return $entity instanceof LegalEntity ? $this->service()->tdsAllocation($entity, $month, $this->user(), sync: false) : collect();
    }

    /** The return a wizard choice pays: false when the choice is incomplete, null when none is generated. */
    private function hintReturn(string $statute, mixed $unit, mixed $month): StatutoryReturn|false|null
    {
        if (! array_key_exists($statute, self::TABS) || ! is_numeric($unit) || ! is_string($month)) {
            return false;
        }
        $model = $this->service()->unit($statute, $unit);
        try {
            $month = $this->service()->month($month);
        } catch (RuntimeException) {
            return false;
        }

        return $model === null ? false : $this->service()->currentReturn($statute, $model, $month);
    }

    private function statuteOf(array $arguments): string
    {
        $statute = (string) ($arguments['statute'] ?? $this->statute);

        return array_key_exists($statute, self::TABS) ? $statute : throw new RuntimeException('Choose a statute.');
    }

    private function cellReturn(array $arguments): ?StatutoryReturn
    {
        try {
            $statute = $this->statuteOf($arguments);
            $unit = $this->service()->unit($statute, $arguments['unit'] ?? null);

            return $unit === null ? null : $this->service()->currentReturn($statute, $unit, $this->service()->month($arguments['month'] ?? null));
        } catch (RuntimeException) {
            return null;
        }
    }

    private function returnById(array $arguments): ?StatutoryReturn
    {
        return is_numeric($arguments['return'] ?? null) ? StatutoryReturn::query()->find((int) $arguments['return']) : null;
    }

    /** A challan of this tenant within the viewer's scope; anything else reads as not found. */
    private function challan(array $arguments): ?StatutoryChallan
    {
        return is_numeric($arguments['challan'] ?? null) ? StatutoryChallan::query()->find((int) $arguments['challan']) : null;
    }

    private function attempt(callable $callback, string $success): void
    {
        try {
            $callback();
            $this->gridMemo = null;
            Notification::make()->success()->title($success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
