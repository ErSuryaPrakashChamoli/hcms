<?php

namespace App\Filament\Resources\CompensationChanges;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Services\SalaryAssignments;
use App\Domain\Compliance\Services\DeclaredRegimes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Letters\Services\Letters;
use App\Filament\Pages\SalaryStructureRegister;
use App\Filament\Resources\CompensationChanges\Pages\ManageCompensationChanges;
use App\Filament\Support\CompensationActions;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PayrollRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/**
 * Phase 11: the compensation change queue across employees (within organisation scope). Full readers
 * (compensation.view) see every change; reviewers, approvers and executors see the changes waiting for
 * their step and those they acted on; nobody sees their own. Proposals start on Employee 360.
 */
class CompensationChangeResource extends Resource
{
    protected static ?string $model = CompensationChange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Compensation';

    protected static ?string $navigationLabel = 'Compensation changes';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['employee.person', 'structure', 'proposer', 'reviewer', 'approver', 'executor']);
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereNotIn('employee_id', Employee::query()->select('id')->where('user_id', $user->id));
        if ($user->hasPermission('compensation.view')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('proposed_by', $user->id)->orWhere('reviewed_by', $user->id)->orWhere('approved_by', $user->id)->orWhere('scheduled_by', $user->id);
            foreach (['submitted' => 'compensation.review', 'under_review' => 'compensation.approve', 'approved' => 'compensation.execute'] as $status => $permission) {
                if ($user->hasPermission($permission)) {
                    $q->orWhere('status', $status);
                }
            }
        });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.employee_code')->label('Employee')->description(fn (CompensationChange $record) => $record->employee?->person?->full_name)->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => CompensationChange::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'effective' => 'success', 'scheduled', 'approved' => 'info', 'rejected', 'cancelled' => 'danger', default => 'warning'
                    }),
                TextColumn::make('change_type')->label('Type')->formatStateUsing(fn (string $state) => config("peopleos.compensation.change_types.{$state}", $state)),
                TextColumn::make('source')->formatStateUsing(fn (string $state) => config("peopleos.compensation.sources.{$state}", $state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('effective_from')->label('Effective')->date()->sortable(),
                TextColumn::make('previous_ctc_annual')->label('From CTC')->numeric(2)->placeholder('—'),
                TextColumn::make('ctc_annual')->label('Proposed CTC')->numeric(2)->description(fn (CompensationChange $record) => $record->currency),
                TextColumn::make('increase')->label('Annual increase')->state(fn (CompensationChange $record) => $record->previous_ctc_annual === null ? null : $record->annualIncrease())->numeric(2)->placeholder('—'),
                TextColumn::make('proposer.name')->label('Proposed by')->toggleable(),
                TextColumn::make('approver.name')->label('Approved by')->placeholder('—')->toggleable(),
                TextColumn::make('reference')->limit(10)->tooltip(fn (CompensationChange $record) => $record->reference)->toggleable(isToggledHiddenByDefault: true),
                // HROne parity PO-2 (§25.2.3 #1): request date and the tax regime of the employee's declaration this financial year.
                TextColumn::make('created_at')->label('Request date')->date()->sortable()->toggleable(),
                TextColumn::make('tax_regime')->label('Tax regime')->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (CompensationChange $record) => SalaryStructureRegister::regimeLabel(app(DeclaredRegimes::class)->forEmployees([(int) $record->employee_id])[(int) $record->employee_id] ?? null)),
            ])
            ->filters([
                SelectFilter::make('status')->options(CompensationChange::STATUSES),
                SelectFilter::make('change_type')->label('Type')->options(config('peopleos.compensation.change_types')),
                // HROne parity PO-1: HROne's "Salary Revision (On) month" and the WO-2 employee filters.
                PayrollRegisters::revisionMonth(),
                ...PayrollRegisters::filters('compensation.view'),
                // HROne parity PO-2 (§25.2.3 #1, same filters as §25.2.1 #2): request status, source, tax regime, request date.
                SelectFilter::make('request_status')->label('Request status')->options(self::REQUEST_STATUSES)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) && isset(self::REQUEST_GROUPS[$data['value']]) ? $query->whereIn('status', self::REQUEST_GROUPS[$data['value']]) : $query),
                SelectFilter::make('source')->label('Source')->multiple()->options((array) config('peopleos.compensation.sources', [])),
                SelectFilter::make('tax_regime')->label('Tax regime (declaration of this financial year)')->multiple()->options(DeclaredRegimes::options())
                    ->query(fn (Builder $query, array $data) => app(DeclaredRegimes::class)->whereRegime($query, $query->getModel()->qualifyColumn('employee_id'), array_values((array) ($data['values'] ?? [])))),
                EmployeeFilters::dateRange('requested', 'Request date', 'created_at'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([CompensationActions::changeSteps(), self::printBreakupAction()])
            ->emptyStateHeading('No compensation changes')
            ->emptyStateDescription('Propose a change from an employee\'s Compensation tab in Employee 360.');
    }

    /** HROne parity PO-2: HROne's request cards (Approved / Pending / Rejected) over the change statuses. */
    public const REQUEST_STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    public const REQUEST_GROUPS = [
        'pending' => SalaryAssignments::OPEN,
        'approved' => CompensationChange::APPROVED,
        'rejected' => ['rejected'],
        'cancelled' => ['cancelled'],
    ];

    /**
     * HROne parity PO-2 (§25.2.2 #21, "Print Salary"): the change's salary annexure as a printable page in the letter
     * studio's layout — for compensation readers; audited as an export.
     */
    public static function printBreakupAction(): Action
    {
        return Action::make('printBreakup')->label('Print breakup')->icon(Heroicon::OutlinedPrinter)->color('gray')
            ->visible(fn () => auth()->user()?->hasPermission('compensation.view') ?? false)
            ->action(function (CompensationChange $record) {
                $employee = Employee::query()->with('person')->find($record->employee_id);
                $segment = app(SalaryAssignments::class)->segmentForChange($record);
                abort_unless($employee !== null && auth()->user()->hasPermission('compensation.view'), 403);
                try {
                    if ($segment === null) {
                        throw new RuntimeException('The structure has no approved version on the effective date.');
                    }
                    $file = app(Letters::class)->breakup($employee, $segment, auth()->user(), $record->reference, ! in_array($record->status, CompensationChange::APPROVED, true));
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Breakup not printed')->body($e->getMessage())->persistent()->send();

                    return null;
                }

                return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => 'text/html; charset=UTF-8']);
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageCompensationChanges::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
