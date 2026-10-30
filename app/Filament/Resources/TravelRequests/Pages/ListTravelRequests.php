<?php

namespace App\Filament\Resources\TravelRequests\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Policies\TravelRequestPolicy;
use App\Domain\Expense\Services\TravelRequests;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptForm;
use App\Filament\Resources\TravelRequests\TravelRequestForm;
use App\Filament\Resources\TravelRequests\TravelRequestResource;
use App\Filament\Support\ExpenseRegisters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\RequestChrome;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListTravelRequests extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = TravelRequestResource::class;

    public static function registerKey(): string
    {
        return 'travel_requests';
    }

    /**
     * HROne parity XO-1 (matrix 26 §26.1 #1, §26.2.3, §26.2.4): HROne's Travel request, Travel bookings and Travel
     * cancellations registers as views of this one register.
     */
    public static function presetViews(): array
    {
        return [
            'waiting' => ['label' => 'Waiting for approval', 'filters' => ['status' => ['values' => ['submitted']]], 'columns' => ['submitted_at', 'waiting_with']],
            'approved' => ['label' => 'Approved trips', 'filters' => ['status' => ['values' => TravelRequest::APPROVED]], 'columns' => ['decided_at', 'decider.name']],
            'bookings' => ['label' => 'Travel bookings to make', 'filters' => ['status' => ['values' => ['booking']]], 'columns' => ['decided_at']],
            'cancellations' => ['label' => 'Travel cancellations', 'filters' => ['cancellation' => ['values' => array_keys(ExpenseRegisters::CANCELLATION)]], 'columns' => ['waiting_with', 'cancel_refused_at']],
            'cancellations_waiting' => ['label' => 'Cancellations waiting for a decision', 'filters' => ['cancellation' => ['values' => ['requested']]], 'columns' => ['waiting_with']],
            'rejected' => ['label' => 'Rejected', 'filters' => ['status' => ['values' => ['rejected']]], 'columns' => ['decided_at', 'decider.name']],
        ];
    }

    public static function registerReports(): array
    {
        return ['travel_summary', 'travel_details', 'accommodation_details', 'live_trip'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the travel datasets */
    public function registerReportFilters(): array
    {
        $status = fn (string $v) => TravelRequest::STATUSES[$v] ?? $v;
        $state = (array) ($this->tableFilters ?? []);

        return [
            ...ExpenseRegisters::reportFilters($state, ['status' => ['status', $status]], ['request_dates' => 'submitted_on', 'decision_dates' => 'decided_on'], ['travel_dates' => ['start_date', 'end_date']]),
            // The journeys and stays dataset names the trip's status `trip_status`.
            ...ExpenseRegisters::reportFilters(array_intersect_key($state, ['status' => true]), ['status' => ['trip_status', $status]]),
        ];
    }

    protected function getHeaderActions(): array
    {
        $submit = function (?Employee $employee, array $data, string $done) {
            try {
                if ($employee === null) {
                    throw new RuntimeException('Choose the employee.');
                }
                $trip = app(TravelRequests::class)->submit($employee, TravelRequestForm::toData($data));
                RequestChrome::confirmed('travel', $trip, $done, $trip->number);
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
            }
        };

        return [
            // HROne parity XO-1: views and report for the HR side; an employee's own list keeps its actions.
            ...(TravelRequestResource::oversees() ? $this->registerToolActions() : []),
            Action::make('requestTravel')->label('Request travel')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('expense.claim') && ExpenseReceiptForm::ownEmployee() !== null)
                // HROne parity RQ-2: whose request, who approves it and the travel policy, around the module's own form.
                ->schema(fn () => [RequestChrome::header(fn () => ExpenseReceiptForm::ownEmployee()), ...TravelRequestForm::schema(fn (Get $get) => ExpenseReceiptForm::ownEmployee()),
                    RequestChrome::route('travel', fn () => ExpenseReceiptForm::ownEmployee())])
                ->action(fn (array $data) => $submit(ExpenseReceiptForm::ownEmployee(), $data, 'Travel request sent for approval')),
            Action::make('requestForEmployee')->label('Request travel for an employee')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->visible(fn () => auth()->user()->can('expense.approve'))
                ->schema(fn () => [
                    Select::make('employee_id')->label('Employee')->required()->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search) => Employee::query()->with('person')->where('user_id', '!=', auth()->id())
                            ->whereHas('person', fn ($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))->limit(20)->get()
                            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->display_name.' ('.$e->employee_code.')']))
                        ->getOptionLabelUsing(fn ($value) => Employee::query()->with('person')->find($value)?->display_name),
                    ...TravelRequestForm::schema(fn (Get $get) => ($id = $get('employee_id') ?? $get('../../employee_id')) ? Employee::query()->find($id) : null),
                ])
                ->action(function (array $data) use ($submit) {
                    $employee = Employee::query()->find($data['employee_id'] ?? null);
                    if ($employee !== null && ! app(TravelRequestPolicy::class)->onBehalf(auth()->user(), $employee)) {
                        $employee = null;
                    }
                    unset($data['employee_id']);
                    $submit($employee, $data, 'Travel request sent for approval for the employee');
                }),
        ];
    }
}
