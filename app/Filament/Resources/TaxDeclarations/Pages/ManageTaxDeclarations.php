<?php

namespace App\Filament\Resources\TaxDeclarations\Pages;

use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Services\InvestmentProofs;
use App\Domain\Compliance\Support\InvestmentCatalogue;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\Employee;
use App\Filament\Pages\DeclareInvestments;
use App\Filament\Resources\TaxDeclarations\TaxDeclarationResource;
use App\Filament\Support\OnBehalfPicker;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleManageRecords;
use App\Filament\Support\PayrollRegisters;
use App\Filament\Support\RequestChrome;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class ManageTaxDeclarations extends PeopleManageRecords
{
    use HasRegisterTools;

    protected static string $resource = TaxDeclarationResource::class;

    public static function registerKey(): string
    {
        return 'tax_declarations';
    }

    public static function presetViews(): array
    {
        return [
            'submitted' => ['label' => 'Proposed investments to verify', 'filters' => ['status' => ['value' => 'submitted']]],
            'verified' => ['label' => 'Verified', 'filters' => ['status' => ['value' => 'verified']]],
            'draft' => ['label' => 'Draft (not yet submitted)', 'filters' => ['status' => ['value' => 'draft']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['investment_declarations', 'declaration_status_summary', 'tax_regime_split'];
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity PO-1: views and report for payroll; the employee's own declaration keeps its action.
            ...(PayrollRegisters::hrSide('payroll.view') ? $this->registerToolActions() : []),
            Action::make('declare')->label('Declare investments')->icon('heroicon-m-clipboard-document-check')
                ->visible(fn () => DeclareInvestments::canAccess())
                ->url(fn () => DeclareInvestments::getUrl()),
            $this->submitProofForEmployee(),
        ];
    }

    /**
     * HROne parity AC-2 (matrix 25 §25.3.3 #3, HROne's global "Confirmed investment" / right "Request on behalf"):
     * payroll sends the proof of an item an employee in its access scope declared — never its own (that is My
     * investment proofs) — as a wizard: the employee, the item, the proof (files and the amount it shows, the same fields as
     * the employee's "Submit proof"), then a review. InvestmentProofs submits it (payroll may at any time, as before); it
     * waits for verification like the employee's own and is recorded as sent on their behalf.
     */
    private function submitProofForEmployee(): Action
    {
        $employee = fn (?Get $get = null): ?Employee => $get !== null ? OnBehalfPicker::picked($get('employee_id'), 'payroll') : null;
        // The items of the chosen employee (the scoped query); whether HR may file for them is checked on submit.
        $items = fn (Employee|int|null $for): Builder => TdsEmployeeInvestment::query()->where('employee_id', $for instanceof Employee ? $for->getKey() : (int) $for)->where('proof_status', '!=', 'verified');
        $item = fn (Get $get): ?TdsEmployeeInvestment => is_numeric($get('item_id')) ? $items((int) $get('employee_id'))->find((int) $get('item_id')) : null;

        return Action::make('submitProofForEmployee')->label('Submit proof for an employee')->icon(Heroicon::OutlinedPaperClip)->color('gray')
            ->visible(fn () => OnBehalfPicker::offered('payroll.manage'))
            ->modalHeading('Submit an investment proof for an employee')
            ->steps([
                Step::make('Employee')->description('Whose proof it is')->schema([
                    OnBehalfPicker::select('payroll')->afterStateUpdated(fn (Set $set) => $set('item_id', null)),
                    RequestChrome::header($employee),
                ]),
                Step::make('Item')->description('The declared item it proves')->schema([
                    Select::make('item_id')->label('Declared item')->required()->live()->native(false)
                        ->options(fn (Get $get) => $items((int) $get('employee_id'))->orderByDesc('financial_year')->orderBy('id')->get()
                            ->mapWithKeys(fn (TdsEmployeeInvestment $row) => [$row->getKey() => self::itemLabel($row)])->all())
                        ->helperText(fn (Get $get) => $employee($get) !== null && $items($employee($get))->doesntExist() ? 'Nothing declared by this employee waits for a proof.' : null)
                        ->afterStateUpdated(fn (Get $get, Set $set) => $set('amount', ($row = $item($get)) !== null ? (float) ($row->proof_claimed_amount ?? $row->declared_amount) : null)),
                    Text::make(fn (Get $get) => ($row = $item($get)) !== null && $row->proof_status === 'rejected' ? 'Payroll did not accept the last proof'.(filled($row->notes) ? ': '.$row->notes : '').'.' : '')
                        ->visible(fn (Get $get) => $item($get)?->proof_status === 'rejected'),
                ]),
                Step::make('Proof')->description('Files and the amount they show')->schema([
                    FileUpload::make('files')->label('Proof (image or PDF)')->multiple()->required()->disk(EmployeeImports::disk())->directory('investment-proofs')->visibility('private')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize((int) config('peopleos.documents.max_kb', 10240)),
                    TextInput::make('amount')->label('Amount the proof shows')->prefix('₹')->numeric()->minValue(1)->required(),
                ]),
                Step::make('Review')->description('Who verifies it')->schema([
                    Text::make('The proof is sent in the employee\'s name and waits for payroll to verify it, like the employee\'s own; nobody verifies their own proof. It records that you sent it.'),
                ]),
            ])
            ->action(function (array $data) use ($items) {
                try {
                    $for = OnBehalfPicker::resolve($data['employee_id'] ?? null, 'payroll.manage', 'payroll', 'My investment proofs');
                    $row = is_numeric($data['item_id'] ?? null) ? $items($for)->find((int) $data['item_id']) : null;
                    if ($row === null) {
                        throw new RuntimeException('Choose one of the items the employee declared.');
                    }
                    app(InvestmentProofs::class)->submit($row, array_values((array) ($data['files'] ?? [])), (float) ($data['amount'] ?? 0), auth()->user());
                    Notification::make()->success()->title('Proof sent to payroll for '.$for->display_name)->body('It waits for verification.')->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
                }
            });
    }

    /** "FY 2026-27 · 80C · Public Provident Fund (PPF) · declared ₹50,000 · proof due". */
    private static function itemLabel(TdsEmployeeInvestment $row): string
    {
        $status = match (true) {
            $row->proof_status === 'rejected' => 'proof rejected',
            $row->proof_submitted_at !== null => 'proof sent, to verify',
            default => 'proof due',
        };

        return implode(' · ', array_filter(['FY '.$row->financial_year, InvestmentCatalogue::SECTIONS[$row->section]['code'] ?? $row->section, $row->description,
            'declared '.DeclareInvestments::inr((float) $row->declared_amount), $status]));
    }
}
