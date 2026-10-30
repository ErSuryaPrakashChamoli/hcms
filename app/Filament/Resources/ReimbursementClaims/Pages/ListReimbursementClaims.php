<?php

namespace App\Filament\Resources\ReimbursementClaims\Pages;

use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Filament\Resources\ReimbursementClaims\ReimbursementClaimResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PayrollRegisters;
use App\Filament\Support\RequestChrome;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListReimbursementClaims extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = ReimbursementClaimResource::class;

    public static function registerKey(): string
    {
        return 'reimbursement_claims';
    }

    /** HROne parity PO-1 (matrix 25 §25.3.1): HROne's Reimbursement requests and cancellations registers as views of this one. */
    public static function presetViews(): array
    {
        return [
            'waiting' => ['label' => 'Waiting for approval', 'filters' => ['status' => ['values' => ['pending']]]],
            'approved' => ['label' => 'Approved, to be paid', 'filters' => ['status' => ['values' => ['approved']]]],
            'paid' => ['label' => 'Paid', 'filters' => ['status' => ['values' => ['paid']]]],
            'cancellations' => ['label' => 'Reimbursement cancellations', 'filters' => ['status' => ['values' => ['cancelled']]]],
            'rejected' => ['label' => 'Rejected', 'filters' => ['status' => ['values' => ['rejected']]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['reimbursement_requests', 'reimbursement_audit', 'reimbursement_summary', 'reimbursement_monthly_ledger'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the reimbursement datasets */
    public function registerReportFilters(): array
    {
        return TimeOfficeRegisters::reportFilters((array) ($this->tableFilters ?? []), ['status' => ['status', null]], ['request_dates' => 'claim_date']);
    }

    protected function getHeaderActions(): array
    {
        $mine = fn () => Employee::query()->where('user_id', auth()->id())->first();

        return [
            // HROne parity PO-1: views, report and the payroll actions for payroll; an employee's own list keeps its action.
            ...(PayrollRegisters::hrSide() ? [...$this->registerToolActions(), PayrollRegisters::actionsMenu()] : []),
            Action::make('claim')->label('Claim a reimbursement')->icon(Heroicon::OutlinedReceiptPercent)
                ->visible(fn () => auth()->user()->can('payroll.request') && $mine() !== null)
                ->schema([
                    // HROne parity RQ-2: whose request it is.
                    RequestChrome::header(fn () => $mine()),
                    Select::make('salary_component_id')->label('Reimbursement')->required()->live()->native(false)
                        ->options(fn () => SalaryComponent::query()->where('type', 'reimbursement')->where('pay_on_claim', true)->orderBy('name')->pluck('name', 'id'))
                        ->helperText(function (Get $get) use ($mine) {
                            $component = SalaryComponent::query()->find($get('salary_component_id'));
                            $claims = app(ReimbursementClaims::class);

                            return $component && $mine() ? 'Available to claim: '.number_format($claims->balance($mine(), $component, $claims->financialYear(now())), 2) : null;
                        }),
                    DatePicker::make('claim_date')->native(false)->default(today())->maxDate(today())->required(),
                    TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                    Textarea::make('description')->required()->maxLength(500),
                    Repeater::make('bills')->schema([
                        TextInput::make('number')->label('Bill no.')->maxLength(60),
                        DatePicker::make('date')->native(false),
                        TextInput::make('vendor')->maxLength(120),
                        TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                    ])->columns(4)->defaultItems(0)->addActionLabel('Add a bill'),
                    FileUpload::make('attachments')->label('Bill images or PDFs')->multiple()->disk(EmployeeImports::disk())->directory('claims')->visibility('private')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize((int) config('peopleos.documents.max_kb', 10240)),
                    // HROne parity RQ-2: who approves it (a read-only preview), who is informed, and the policy.
                    RequestChrome::route('reimbursement', fn () => $mine()),
                ])
                ->action(function (array $data) use ($mine) {
                    try {
                        $claim = app(ReimbursementClaims::class)->claim($mine(), SalaryComponent::query()->findOrFail($data['salary_component_id']), (float) $data['amount'], $data['claim_date'], $data['description'], $data['bills'] ?? [], array_values((array) ($data['attachments'] ?? [])));
                        RequestChrome::confirmed('reimbursement', $claim, 'Claim sent for approval');
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
