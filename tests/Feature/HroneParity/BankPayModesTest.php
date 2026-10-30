<?php

use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\ChangeBankAccountAction;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\BankAccountImports;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\BankAccountRule;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Payroll\Models\BankFileLayout;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\BankFile;
use App\Domain\Payroll\Services\BankFileLayouts;
use App\Domain\Payroll\Services\PaymentModes;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\BankAccountRules\BankAccountRuleResource;
use App\Filament\Resources\BankAccountRules\Pages\ManageBankAccountRules;
use App\Filament\Resources\BankFileLayouts\BankFileLayoutResource;
use App\Filament\Resources\BankFileLayouts\Pages\ManageBankFileLayouts;
use App\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource;
use App\Filament\Resources\CompanyBankAccounts\Pages\ManageCompanyBankAccounts;
use App\Filament\Resources\EmployeePaymentModes\EmployeePaymentModeResource;
use App\Filament\Resources\EmployeePaymentModes\Pages\ListEmployeePaymentModes;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\BankAccountsRelationManager;
use App\Filament\Resources\VariablePayPlans\VariablePayPlanResource;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne setup parity BP-13 / CA-5: pay modes, paying bank accounts, versioned bank file layouts, the cash and cheque register, account number rules. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'analytics.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->modes = app(PaymentModes::class);
    $this->settings = app(SettingsRepository::class);
});

function bpmBank($employee, string $ifsc, string $number = '123456789012'): void
{
    EmployeeBankAccount::create(['employee_id' => $employee->id, 'account_holder_name' => 'Holder '.$employee->id, 'bank_name' => substr($ifsc, 0, 4).' Bank', 'account_number' => $number, 'ifsc' => $ifsc, 'is_primary' => true]);
}

function bpmCloseSeptember(): PayrollRun
{
    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open(test()->company, 2026, 9, test()->hr), test()->hr);

    return $runs->finalize($runs->approve($runs->validate($run), test()->approver, 'ok'), test()->approver);
}

it('leaves cash and cheque payments out of the bank file, lists them in the register and needs no bank account for them', function () {
    $meal = SalaryComponent::create(['name' => 'Meal reimbursement', 'code' => 'MEALR', 'type' => 'reimbursement', 'classification' => 'reimbursement', 'calculation_method' => 'fixed',
        'taxable' => false, 'include_in_ctc' => true, 'include_in_gross' => false, 'is_recurring' => true, 'is_proratable' => false, 'sort_order' => 60, 'status' => 'active']);
    approveStructureVersion('STANDARD', '2026-01-01', [$meal->id => 60]);
    $transfer = salariedEmployee(600000, from: '2026-01-01');
    $cash = salariedEmployee(300000, from: '2026-01-01');
    $split = salariedEmployee(480000, from: '2026-01-01', componentValues: ['CONV' => 1600, 'MEALR' => 1000]);
    bpmBank($transfer, 'HDFC0000001');
    bpmBank($split, 'HDFC0000002');

    expect(fn () => $this->modes->set($cash, '2026-09-01', 'cash', null, null, null, 'Site worker', $this->hr))->toThrow(RuntimeException::class, 'Cash is not a pay mode in use');
    $this->settings->set('payroll.pay_modes', ['online_transfer', 'cash', 'cheque']);
    $this->modes->set($cash, '2026-09-01', 'cash', null, null, null, 'Site worker', $this->hr);
    $this->modes->set($split, '2026-09-01', 'online_transfer', null, 'cheque', null, 'Reimbursements by cheque', $this->hr);
    expect(fn () => $this->modes->set($split, '2026-09-01', 'cash', CompanyBankAccount::query()->value('id') ?? 1, null, null, 'x', $this->hr))->toThrow(RuntimeException::class, 'bank transfers only')
        ->and(fn () => $this->modes->set($split, '2026-09-01', 'cash', null, null, null, 'x', tenantUser($this->tenant, ['payroll.view'])))->toThrow(RuntimeException::class, 'Only payroll');

    $run = bpmCloseSeptember();
    $entries = PayrollEntry::query()->where('payroll_run_id', $run->id)->get()->keyBy('employee_id');
    expect(collect($entries[$cash->id]->exceptions ?? [])->pluck('type')->all())->not->toContain('no_bank');

    $rows = collect(app(BankFile::class)->rows($run))->keyBy('employee_code');
    expect($rows->keys()->all())->toEqualCanonicalizing([$transfer->employee_code, $split->employee_code])
        ->and($rows[$transfer->employee_code]['amount'])->toBe(number_format((float) $entries[$transfer->id]->net_pay, 2, '.', ''))
        ->and($rows[$split->employee_code]['amount'])->toBe(number_format((float) $entries[$split->id]->net_pay - 1000, 2, '.', ''));

    $library = app(ReportLibrary::class)->find('cash_cheque_register');
    $register = collect(app(ReportRunner::class)->execute('salary_payments', $library['definition'], $this->hr)->rows)->keyBy('employee_code');
    expect($register->keys()->all())->toEqualCanonicalizing([$cash->employee_code, $split->employee_code])
        ->and($register[$cash->employee_code]['cash'])->toBe((float) $entries[$cash->id]->net_pay)->and($register[$cash->employee_code]['salary_mode'])->toBe('Cash')
        ->and($register[$split->employee_code]['cheque'])->toBe(1000.0)->and($register[$split->employee_code]['reimbursement_mode'])->toBe('Cheque');
});

it('groups transfers by paying account and writes an account\'s file in its bank\'s versioned layout', function () {
    $layouts = app(BankFileLayouts::class);
    $draft = $layouts->create(['code' => 'hdfc_sal', 'name' => 'HDFC salary upload', 'specification' => [
        'format' => 'delimited', 'delimiter' => '|', 'quotes' => 'never', 'column_headings' => false,
        'columns' => [['field' => 'transfer_type', 'format' => 'I|N'], ['field' => 'beneficiary_name', 'case' => 'upper'], ['field' => 'account_number'], ['field' => 'ifsc'], ['field' => 'amount', 'format' => 'paise'], ['field' => 'literal', 'value' => 'SAL']],
        'header_lines' => ['H|{debit_account}|{count}|{total}|{payment_date:dmY}'], 'footer_lines' => ['T|{total_paise}'],
    ]], $this->hr);
    expect($draft->code)->toBe('HDFC_SAL')->and($draft->status)->toBe('draft')
        ->and(fn () => $layouts->create(['code' => 'HDFC_SAL', 'name' => 'Again', 'specification' => ['columns' => [['field' => 'amount']]]], $this->hr))->toThrow(RuntimeException::class, 'make a new version')
        ->and(fn () => $layouts->updateDraft($draft, ['specification' => ['columns' => [['field' => 'salary']]]], $this->hr))->toThrow(RuntimeException::class, 'choose what it prints')
        ->and(fn () => $layouts->updateDraft($draft, ['specification' => ['columns' => [['field' => 'amount']], 'header_lines' => ['{secret}']]], $this->hr))->toThrow(RuntimeException::class, 'Unknown placeholder');
    $active = $layouts->activate($draft, $this->hr, 'Bank sent the format');
    expect($active->status)->toBe('active')->and($active->checksumIntact())->toBeTrue()
        ->and(fn () => $active->update(['specification' => ['columns' => [['field' => 'amount']]]]))->toThrow(RuntimeException::class, 'make a new version');

    $hdfc = CompanyBankAccount::create(['company_id' => $this->company->id, 'alias' => 'HDFC salary', 'bank_name' => 'HDFC Bank', 'ifsc' => 'hdfc0009999', 'account_number' => '50200012345678', 'bank_file_layout_code' => 'HDFC_SAL', 'is_default' => true]);
    $icici = CompanyBankAccount::create(['company_id' => $this->company->id, 'alias' => 'ICICI salary', 'bank_name' => 'ICICI Bank', 'ifsc' => 'ICIC0001111', 'account_number' => '000405001234']);
    expect($hdfc->ifsc)->toBe('HDFC0009999')->and($hdfc->account_number_last4)->toBe('5678')->and($hdfc->getAttributes()['account_number'])->not->toContain('50200012345678');

    [$sameBank, $otherBank, $atIcici, $chosen] = [salariedEmployee(600000, from: '2026-01-01'), salariedEmployee(500000, from: '2026-01-01'), salariedEmployee(400000, from: '2026-01-01'), salariedEmployee(450000, from: '2026-01-01')];
    bpmBank($sameBank, 'HDFC0000001', '111122223333');
    bpmBank($otherBank, 'SBIN0000002', '444455556666');
    bpmBank($atIcici, 'ICIC0000003', '777788889999');
    bpmBank($chosen, 'HDFC0000004', '121212121212');
    $this->modes->set($chosen, '2026-09-01', 'online_transfer', $icici->id, null, null, 'Paid from ICICI', $this->hr);

    $run = bpmCloseSeptember();
    $bankFile = app(BankFile::class);
    $groups = $bankFile->groups($run);
    expect(array_keys($groups))->toEqualCanonicalizing([$hdfc->id, $icici->id])->and($groups[$hdfc->id]['count'])->toBe(2)->and($groups[$icici->id]['count'])->toBe(2)
        ->and(collect($bankFile->rows($run, $icici->id))->pluck('employee_code')->all())->toEqualCanonicalizing([$atIcici->employee_code, $chosen->employee_code]);

    $net = fn ($e) => (float) PayrollEntry::query()->where('payroll_run_id', $run->id)->where('employee_id', $e->id)->value('net_pay');
    $total = round($net($sameBank) + $net($otherBank), 2);
    $file = $bankFile->csv($run, $hdfc->id);
    $lines = explode("\n", trim($file));
    expect($lines[0])->toBe('H|50200012345678|2|'.number_format($total, 2, '.', '').'|'.($run->period->payment_date ?? $run->period->end_date)->format('dmY'))
        ->and($lines)->toContain('I|HOLDER '.$sameBank->id.'|111122223333|HDFC0000001|'.(int) round($net($sameBank) * 100).'|SAL')
        ->and($lines)->toContain('N|HOLDER '.$otherBank->id.'|444455556666|SBIN0000002|'.(int) round($net($otherBank) * 100).'|SAL')
        ->and(end($lines))->toBe('T|'.(int) round($total * 100))
        ->and($bankFile->fileName($run, $hdfc->id))->toBe('salary-2026-09-hdfc-salary-5678.csv');
    $audit = AuditEvent::query()->where('action', 'EXPORT')->where('module', 'payroll')->latest('id')->first();
    expect($audit->metadata)->toMatchArray(['export' => 'bank_file', 'rows' => 2, 'layout' => 'HDFC_SAL', 'layout_version' => 1]);

    // The ICICI account has no layout: its transfers go out in the plain file.
    expect(explode("\n", $bankFile->csv($run, $icici->id))[0])->toBe('"Employee code","Beneficiary name",Bank,IFSC,"Account number",Amount,Narration');

    // A change is a new version; activating it retires the first and the next file uses it.
    $v2 = $layouts->newVersion($active, $this->hr);
    expect($v2->version)->toBe(2)->and(fn () => $layouts->newVersion($active, $this->hr))->toThrow(RuntimeException::class, 'already has a draft');
    $layouts->updateDraft($v2, ['specification' => ['format' => 'fixed_width', 'column_headings' => false, 'columns' => [['field' => 'employee_code', 'width' => 8], ['field' => 'amount', 'width' => 12, 'align' => 'right', 'pad' => '0']]]], $this->hr);
    $layouts->activate($v2->refresh(), $this->hr);
    expect(BankFileLayout::query()->where('code', 'HDFC_SAL')->orderBy('version')->pluck('status')->all())->toBe(['retired', 'active']);
    $fixed = explode("\n", trim($bankFile->csv($run, $hdfc->id)));
    expect(strlen($fixed[0]))->toBe(20)->and(str_pad(substr($sameBank->employee_code, 0, 8), 8))->toBeIn(array_map(fn ($l) => substr($l, 0, 8), $fixed))
        ->and(substr($fixed[0], 8))->toBe(str_pad(number_format($net(collect([$sameBank, $otherBank])->first(fn ($e) => str_starts_with($fixed[0], str_pad(substr($e->employee_code, 0, 8), 8)))), 2, '.', ''), 12, '0', STR_PAD_LEFT));
});

it('checks employees\' account numbers against their bank\'s rule, on screen and in uploads', function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    BankAccountRule::create(['bank_name' => 'HDFC Bank', 'ifsc_prefix' => 'hdfc', 'min_length' => 14, 'max_length' => 14, 'allow_digits' => true, 'allow_letters' => false, 'allow_special' => false]);
    $employee = salariedEmployee(600000, from: '2026-01-01');
    $action = app(ChangeBankAccountAction::class);
    $bank = fn (string $number, string $ifsc = 'HDFC0000001') => ['account_holder_name' => 'Holder', 'bank_name' => 'HDFC Bank', 'account_number' => $number, 'ifsc' => $ifsc, 'is_primary' => true];

    expect(fn () => $action->add($employee, $bank('12345'), $this->hr))->toThrow(ProfileChangeRefused::class, 'at least 14 characters')
        ->and(fn () => $action->add($employee, $bank('1234567890123A'), $this->hr))->toThrow(ProfileChangeRefused::class, 'may hold only digits')
        ->and($action->add($employee, $bank('SB12', 'SBIN0000001'), $this->hr)->bank_name)->toBe('HDFC Bank'); // another bank: no rule
    $account = $action->add($employee, $bank('12345678901234'), $this->hr);
    expect(fn () => $action->update($account, ['account_number' => '999'] + $bank('999'), $this->hr))->toThrow(ProfileChangeRefused::class, 'at least 14');

    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, "Employee code,Account holder name,Bank name,Account number,IFSC\n{$employee->employee_code},Holder,HDFC Bank,123,HDFC0000009\n");
    $imports = app(BankAccountImports::class);
    $import = $imports->inspect($imports->register($path, 'bank.csv', $this->hr));
    $import = $imports->validate($imports->map($import, $import->mapping));
    expect($import->error_count)->toBe(1)->and($import->rows()->first()->errors)->toContain('HDFC Bank account numbers have at least 14 characters.');
});

it('shows the IFSC and branch to HR unless the organisation hides them', function () {
    $employee = salariedEmployee(600000, from: '2026-01-01');
    bpmBank($employee, 'HDFC0000001');
    $tab = fn () => Livewire::test(BankAccountsRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => ViewEmployee::class]);

    $tab()->assertTableColumnVisible('ifsc');
    $this->settings->set('payroll.bank.show_ifsc_branch', false);
    $tab()->assertTableColumnHidden('ifsc');
});

it('keeps the bank and pay mode screens to payroll', function () {
    foreach ([CompanyBankAccountResource::class, BankFileLayoutResource::class, EmployeePaymentModeResource::class, BankAccountRuleResource::class, VariablePayPlanResource::class] as $resource) {
        $this->get($resource::getUrl('index'))->assertOk();
    }
    $this->actingAs(tenantUser($this->tenant, ['payroll.view']));
    foreach ([CompanyBankAccountResource::class, BankFileLayoutResource::class, EmployeePaymentModeResource::class, BankAccountRuleResource::class] as $resource) {
        $this->get($resource::getUrl('index'))->assertForbidden();
    }
    expect(fn () => app(BankFileLayouts::class)->create(['code' => 'X1', 'name' => 'X', 'specification' => ['columns' => [['field' => 'amount']]]], auth()->user()))->toThrow(RuntimeException::class, 'payroll.manage');
});

it('keeps paying accounts, account number rules, layouts and payment modes on their screens', function () {
    Livewire::test(ManageCompanyBankAccounts::class)
        ->callAction('create', ['company_id' => $this->company->id, 'alias' => 'Main', 'bank_name' => 'HDFC Bank', 'ifsc' => 'hdfc0001234', 'account_number' => '50200011112222', 'is_default' => true, 'status' => 'active'])
        ->assertHasNoActionErrors();
    $account = CompanyBankAccount::query()->sole();
    expect($account->account_number_last4)->toBe('2222')->and($account->ifsc)->toBe('HDFC0001234');

    Livewire::test(ManageBankAccountRules::class)->callAction('create', ['bank_name' => '', 'ifsc_prefix' => ''])->assertHasActionErrors(['bank_name' => 'required']);
    Livewire::test(ManageBankAccountRules::class)->callAction('create', ['bank_name' => 'State Bank', 'min_length' => 11, 'max_length' => 9])->assertHasActionErrors(['max_length']);
    Livewire::test(ManageBankAccountRules::class)->callAction('create', ['bank_name' => 'State Bank', 'ifsc_prefix' => 'sbin', 'min_length' => 11, 'max_length' => 11, 'allow_digits' => true, 'allow_letters' => false, 'allow_special' => false, 'status' => 'active'])
        ->assertHasNoActionErrors();
    expect(BankAccountRule::query()->sole()->ifsc_prefix)->toBe('SBIN');

    Livewire::test(ManageBankFileLayouts::class)
        ->callAction('create', ['code' => 'plain_two', 'name' => 'Two columns', 'format' => 'delimited', 'delimiter' => ',', 'quotes' => 'as_needed', 'column_headings' => true,
            'columns' => [['field' => 'employee_code', 'heading' => 'Code'], ['field' => 'amount', 'heading' => 'Amount', 'format' => 'whole']], 'header_lines' => "Batch {batch}\n", 'line_ending' => 'crlf', 'extension' => 'txt'])
        ->assertNotified('Layout drafted; activate it when it is right');
    $layout = BankFileLayout::query()->where('code', 'PLAIN_TWO')->sole();
    expect($layout->specification['header_lines'])->toBe(['Batch {batch}'])->and($layout->specification['line_ending'])->toBe('crlf')
        ->and(BankFileLayoutResource::sample($layout))->toStartWith("Batch 1\r\nCode,Amount\r\nE001,45251\r\n");

    $this->settings->set('payroll.pay_modes', ['online_transfer', 'cheque']);
    $employee = salariedEmployee(300000, from: '2026-01-01');
    Livewire::test(ListEmployeePaymentModes::class)
        ->callAction('set', ['employee_id' => $employee->id, 'effective_from' => '2026-09-01', 'salary_mode' => 'cheque', 'reason' => 'No bank account yet'])
        ->assertNotified('Payment mode saved');
    expect($this->modes->for($employee->id, now())->salary_mode)->toBe('cheque');
});
