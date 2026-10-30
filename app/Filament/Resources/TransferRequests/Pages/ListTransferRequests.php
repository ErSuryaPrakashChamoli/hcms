<?php

namespace App\Filament\Resources\TransferRequests\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\WorkforceReason;
use App\Domain\Employment\Services\TransferRequests;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\TransferRequests\TransferRequestResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListTransferRequests extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = TransferRequestResource::class;

    public static function registerKey(): string
    {
        return 'transfer_requests';
    }

    public static function presetViews(): array
    {
        return [
            'pending' => ['label' => 'Waiting for a decision', 'filters' => ['status' => ['value' => 'pending']]],
            'approved' => ['label' => 'Approved', 'filters' => ['status' => ['value' => 'approved']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['transfers', 'promotions', 'manager_changes'];
    }

    protected function getHeaderActions(): array
    {
        $dimensions = collect(EmployeePosition::DIMENSIONS)->map(function (array $meta, string $column) {
            [$relation, $label] = $meta;
            $model = (new EmployeePosition)->{$relation}()->getRelated();

            return Select::make("changes.{$column}")->label($label)->options(fn () => $model->newQuery()->orderBy('name')->pluck('name', 'id'))->searchable()->placeholder('Unchanged');
        })->values()->all();

        return [
            ...$this->registerToolActions(),
            Action::make('request')->label('Request a transfer')->icon(Heroicon::OutlinedArrowsRightLeft)
                ->schema([
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                    DatePicker::make('effective_date')->label('From')->native(false)->required()->default(now()->startOfMonth()->addMonth()),
                    Grid::make(3)->schema($dimensions),
                    Select::make('new_manager_id')->label('New reporting manager')->options(fn () => CreateEmployee::managerOptions())->searchable()->placeholder('Unchanged'),
                    Select::make('workforce_reason_id')->label('Reason')->options(fn () => WorkforceReason::query()->ofKind('transfer')->pluck('name', 'id'))->native(false),
                    // HROne setup parity 08.6: transfer allowances (pay components marked as such), paid once by payroll.
                    Repeater::make('allowances')->label('Transfer allowances')->columns(2)->defaultItems(0)->addActionLabel('Add an allowance')
                        ->visible(fn () => SalaryComponent::query()->where('status', 'active')->where('transfer_allowance', true)->exists())
                        ->helperText('Paid once with the payroll of the month the transfer takes effect, after approval.')
                        ->schema([
                            Select::make('salary_component_id')->label('Allowance')->required()->native(false)
                                ->options(fn () => SalaryComponent::query()->where('status', 'active')->where('transfer_allowance', true)->whereIn('type', ['earning', 'reimbursement'])->orderBy('name')->pluck('name', 'id')->all()),
                            TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                        ]),
                    Textarea::make('note')->maxLength(1000),
                ])
                ->action(function (array $data) {
                    try {
                        app(TransferRequests::class)->request(Employee::query()->findOrFail($data['employee_id']), $data['effective_date'], (array) ($data['changes'] ?? []),
                            isset($data['new_manager_id']) ? (int) $data['new_manager_id'] : null, isset($data['workforce_reason_id']) ? (int) $data['workforce_reason_id'] : null, $data['note'] ?? null, auth()->user(), array_values((array) ($data['allowances'] ?? [])));
                        Notification::make()->success()->title('Transfer requested')->body('Someone else with position rights approves it.')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not requested')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
