<?php

namespace App\Filament\Resources\EmployeePaymentModes\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Domain\Payroll\Services\PaymentModes;
use App\Filament\Resources\EmployeePaymentModes\EmployeePaymentModeResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListEmployeePaymentModes extends PeopleListRecords
{
    protected static string $resource = EmployeePaymentModeResource::class;

    protected function getHeaderActions(): array
    {
        $accounts = fn () => CompanyBankAccount::query()->with('company')->where('status', 'active')->orderBy('company_id')->get()
            ->mapWithKeys(fn (CompanyBankAccount $a) => [$a->id => $a->label().' · '.$a->company?->name])->all();

        return [
            Action::make('set')->label('Set payment mode')->icon(Heroicon::OutlinedBanknotes)
                ->modalDescription('Applies from the date given until a later change. Bank transfers go in the bank file; cash and cheque payments are listed in the cash and cheque register. Net pay is not changed.')
                ->schema([
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                    DatePicker::make('effective_from')->label('From')->native(false)->default(now()->startOfMonth())->required(),
                    Select::make('salary_mode')->label('Salary paid by')->options(fn () => app(PaymentModes::class)->enabled())->default('online_transfer')->required()->live()->native(false),
                    Select::make('salary_bank_account_id')->label('Paid from')->options($accounts)->placeholder('The account at the employee\'s bank, else the default')
                        ->visible(fn (Get $get) => $get('salary_mode') === 'online_transfer'),
                    Select::make('reimbursement_mode')->label('Reimbursements paid by')->options(fn () => app(PaymentModes::class)->enabled())->placeholder('As the salary')->live()->native(false),
                    Select::make('reimbursement_bank_account_id')->label('Reimbursements paid from')->options($accounts)->placeholder('As the salary')
                        ->visible(fn (Get $get) => $get('reimbursement_mode') === 'online_transfer'),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        app(PaymentModes::class)->set(Employee::query()->findOrFail($data['employee_id']), $data['effective_from'], $data['salary_mode'],
                            filled($data['salary_bank_account_id'] ?? null) ? (int) $data['salary_bank_account_id'] : null, $data['reimbursement_mode'] ?? null,
                            filled($data['reimbursement_bank_account_id'] ?? null) ? (int) $data['reimbursement_bank_account_id'] : null, $data['reason'], auth()->user());
                        Notification::make()->success()->title('Payment mode saved')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
