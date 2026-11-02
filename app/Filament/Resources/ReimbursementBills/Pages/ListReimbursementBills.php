<?php

namespace App\Filament\Resources\ReimbursementBills\Pages;

use App\Domain\Claims\Services\ReimbursementBills;
use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\ReimbursementBills\ReimbursementBillResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListReimbursementBills extends PeopleListRecords
{
    protected static string $resource = ReimbursementBillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record')->label('Record a bill')->icon(Heroicon::OutlinedPlus)
                ->schema([
                    Section::make('Whose reimbursement')->columns(2)->schema([
                        Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                        Select::make('salary_component_id')->label('Reimbursement paid with salary')->required()->native(false)
                            ->options(fn () => SalaryComponent::query()->where('type', 'reimbursement')->where('pay_on_claim', false)->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                    ]),
                    Section::make('The bill')->columns(2)->schema([
                        DatePicker::make('bill_date')->native(false)->maxDate(today())->required(),
                        TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                        TextInput::make('bill_number')->label('Bill no.')->maxLength(60),
                        TextInput::make('vendor')->maxLength(120),
                        TextInput::make('description')->maxLength(255)->columnSpanFull(),
                    ]),
                ])
                ->action(function (array $data) {
                    try {
                        app(ReimbursementBills::class)->record(Employee::query()->findOrFail($data['employee_id']), SalaryComponent::query()->findOrFail($data['salary_component_id']),
                            $data['bill_date'], (float) $data['amount'], $data['bill_number'] ?? null, $data['vendor'] ?? null, $data['description'] ?? null, auth()->user());
                        Notification::make()->success()->title('Bill recorded')->body('From the bills date, TDS counts the reimbursement beyond its bills as taxable.')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
