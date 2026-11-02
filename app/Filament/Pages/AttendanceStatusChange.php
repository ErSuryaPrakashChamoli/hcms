<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Services\AttendanceMonthPopulation;
use App\Domain\Attendance\Services\AttendanceOverrides;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\BulkOperations\ChangeAttendanceStatusOperation;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Filament\Resources\AttendanceOverrides\AttendanceOverrideResource;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\RegisterTablePage;
use App\Filament\Support\TimeOfficeRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * HROne parity TX-6 (matrix 24 §24.8 #9 — global action "Change attendance status": the employee picker with "Add bulk
 * employees", organisation, location, status and type, then From, To, First half, Second half; §24.5.1 #4; §24.5.2 #4;
 * §24.5.3 #6; the HR part of §24.13 #3): employees chosen with WO-2's filters (pasted codes, organisation, status / type /
 * joining, pending resignation) inside the viewer's access scope, then "Change attendance status" on the selected ones —
 * a date range and a status for the whole day or for each half, with a reason — through AttendanceOverrides::setRange
 * (each day through AttendanceOverrides::set, audited and recalculated). The result lists each employee done and each
 * refused employee or day with the reason. Selections above `peopleos.bulk.confirm_over` run as a WO-1 bulk run (bulk.run,
 * typed count). attendance.view with attendance.override (TX-2; attendance.manage still grants it) opens the page.
 */
class AttendanceStatusChange extends RegisterTablePage
{
    use HasRegisterTools;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Change attendance status';

    protected static ?string $title = 'Change attendance status';

    protected static ?string $slug = 'change-attendance-status';

    protected static ?int $navigationSort = 61;

    protected string $view = 'filament.pages.attendance-status-change';

    public const REGISTER = 'attendance_status';

    /**
     * The last direct change's result summary, shown above the employees.
     *
     * @var array{title: string, days: int, done: list<array<string, mixed>>, refused: list<array<string, mixed>>}|null
     */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasPermission('attendance.view') && $user->hasPermission('attendance.override');
    }

    public static function registerKey(): string
    {
        return self::REGISTER;
    }

    public function getSubheading(): ?string
    {
        return 'Choose employees with the filters or pasted codes, select them, then set the status of a range of days — for the whole day or for each half.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->population())
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['person', 'currentPosition.department', 'currentPosition.designation']))
            ->columns([
                TextColumn::make('employee_code')->label('Code')->sortable(),
                TextColumn::make('person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search())->wrap(),
                TextColumn::make('currentPosition.department.name')->label('Department')->placeholder('—')->toggleable(),
                TextColumn::make('currentPosition.designation.name')->label('Designation')->placeholder('—')->toggleable(),
                TextColumn::make('lifecycle_state')->label('Status')->badge()->formatStateUsing(fn ($state) => $state instanceof LifecycleState ? $state->getLabel() : str_replace('_', ' ', (string) $state)),
                TextColumn::make('joining_date')->label('Joined')->date('d M Y')->placeholder('—')->toggleable(),
                TextColumn::make('exit_date')->label('Left')->date('d M Y')->placeholder('—')->toggleable(),
            ])
            ->filters([
                EmployeeFilters::codes(),
                EmployeeFilters::organisation(),
                EmployeeFilters::workforce(),
                EmployeeFilters::excludePendingResignation(),
            ])
            ->recordActions([
                Action::make('calendar')->label('Calendar')->icon(Heroicon::OutlinedCalendar)->color('gray')
                    ->visible(fn () => AttendanceCalendar::canAccess())
                    ->url(fn (Employee $record) => AttendanceCalendar::getUrl(['employee' => $record->id, 'month' => now()->format('Y-m')])),
            ])
            ->toolbarActions([$this->changeStatusAction()])
            ->defaultSort('employee_code')
            ->paginated([10, 25, 50, 100]);
    }

    /** Employees who have joined and are employed now or left within the last year, inside the viewer's access scope. */
    private function population(): Builder
    {
        return Employee::query()
            ->whereIn('lifecycle_state', AttendanceMonthPopulation::states())
            ->where(fn (Builder $q) => $q->whereNull('joining_date')->orWhere('joining_date', '<=', now()->toDateString().' 23:59:59'))
            ->where(fn (Builder $q) => $q->whereNull('exit_date')->orWhere('exit_date', '>=', now()->subYear()->toDateString()));
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            TimeOfficeRegisters::uploads(['day_overrides', 'attendance_sheet']),
            Action::make('dayOverrides')->label('Day overrides list')->icon(Heroicon::OutlinedTableCells)->color('gray')
                ->visible(fn () => AttendanceOverrideResource::canViewAny())
                ->url(fn () => AttendanceOverrideResource::getUrl('index')),
        ];
    }

    // --- Change attendance status for the selected employees --------------------------------------------------------------

    private function changeStatusAction(): BulkAction
    {
        return BulkAction::make('changeStatus')->label('Change attendance status')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('primary')
            ->deselectRecordsAfterCompletion()
            ->modalHeading(fn (BulkAction $action) => 'Change attendance status · '.count($action->getSelectedRecords()).' employee(s)')
            ->modalSubmitActionLabel('Change')
            ->steps(function (BulkAction $action) {
                $count = count($action->getSelectedRecords());
                $today = now()->toDateString();

                return [
                    Step::make('Days and status')->description('The days, and the status of the whole day or each half')
                        ->schema([...ChangeAttendanceStatusOperation::rangeFields($today, $today), ...ChangeAttendanceStatusOperation::statusFields()]),
                    Step::make('Review')->description('What happens, and why')->schema([
                        Text::make(fn (Get $get) => $this->summary($count, $get)),
                        Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                        TextInput::make('confirm_count')->label('Type '.$count.' to confirm')->visible($count > BulkRuns::confirmOver())->required($count > BulkRuns::confirmOver())
                            ->in([(string) $count])->validationMessages(['in' => 'Type exactly '.$count.'.']),
                    ]),
                ];
            })
            ->action(fn (BulkAction $action, array $data) => $this->runOnSelected(collect($action->getSelectedRecords()), $data));
    }

    private function summary(int $count, Get $get): string
    {
        try {
            [$status, $first, $second] = ChangeAttendanceStatusOperation::statusesFrom(['mode' => $get('mode'), 'status' => $get('status'), 'first_half' => $get('first_half'), 'second_half' => $get('second_half')]);
            $what = AttendanceOverrides::describe($status, $first, $second);
        } catch (RuntimeException $e) {
            $what = '… ('.$e->getMessage().')';
        }

        return 'Sets '.($what !== '' ? $what : '…').' on every day from '.$this->dateLabel($get('from')).' to '.$this->dateLabel($get('to')).' for '.$count.' employee(s), as an HR day override with the reason below; each day is recalculated. '
            .'Employees outside your access and your own attendance are refused; days before joining or after leaving, locked days and days of a closed attendance month are listed and left as they are. Approved full-day leave keeps the day. '
            .$this->runNote($count);
    }

    private function runNote(int $count): string
    {
        if ($count <= BulkRuns::confirmOver()) {
            return 'It runs now and the result is shown above the employees.';
        }

        return app(BulkOperations::class)->allows(auth()->user(), ChangeAttendanceStatusOperation::KEY)
            ? 'More than '.BulkRuns::confirmOver().' employees: it runs as a bulk run in the background (People → Bulk runs lists each employee).'
            : 'More than '.BulkRuns::confirmOver().' employees need the bulk run right (bulk.run): select fewer employees.';
    }

    private function dateLabel(mixed $date): string
    {
        try {
            return filled($date) ? Carbon::parse((string) $date)->format('j M Y') : '…';
        } catch (Throwable) {
            return '…';
        }
    }

    /**
     * Small selections run now through AttendanceOverrides::setRange (result summary above the employees); larger ones
     * start a WO-1 bulk run.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, mixed>  $data
     */
    public function runOnSelected(Collection $employees, array $data): void
    {
        abort_unless(static::canAccess(), 403);
        $confirmed = isset($data['confirm_count']) && is_numeric($data['confirm_count']) ? (int) $data['confirm_count'] : null;
        unset($data['confirm_count']);

        if ($employees->count() > BulkRuns::confirmOver()) {
            try {
                $run = app(BulkRuns::class)->start(auth()->user(), self::REGISTER, ChangeAttendanceStatusOperation::KEY, $employees->map(fn (Employee $e) => $e->getKey())->all(), $data, 'selected', $confirmed);
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not started')->body($e->getMessage())->send();

                return;
            }
            $run->refresh();
            Notification::make()->status($run->status === 'failed' ? 'danger' : ($run->refused + $run->failed > 0 ? 'warning' : 'success'))
                ->title($run->operation_label.': '.($run->isFinished() ? $run->summary() : 'queued for '.$run->total.' employee(s)'))
                ->body($run->isFinished() && $run->refused + $run->failed > 0 ? 'Some employees were not changed; the run lists each one with the reason.' : null)
                ->actions([Action::make('open')->label('See each employee')->url(BulkRunResource::getUrl('view', ['record' => $run]))])->send();

            return;
        }

        try {
            $input = app(BulkOperations::class)->get(ChangeAttendanceStatusOperation::KEY)->prepare($data, auth()->user());
            $result = app(AttendanceOverrides::class)->setRange($employees, $input['from'], $input['to'], $input['status'], $input['first_half'], $input['second_half'], $input['reason'], auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();

            return;
        }
        $this->showResult('Change attendance status '.$this->dateLabel($input['from']).' – '.$this->dateLabel($input['to']), $result);
    }

    /** @param  array{days: int, done: list<array<string, mixed>>, refused: list<array<string, mixed>>}  $result */
    private function showResult(string $title, array $result): void
    {
        $this->result = ['title' => $title] + $result;
        $refused = count($result['refused']);
        $first = $result['refused'][0] ?? null;
        Notification::make()->status($refused === 0 ? 'success' : (count($result['done']) === 0 ? 'danger' : 'warning'))
            ->title($title)
            ->body(count($result['done']).' employee(s) changed ('.$result['days'].' day(s)), '.$refused.' refusal(s)'
                .($first !== null ? ': '.$first['label'].($first['dates'] ? ' · '.$first['dates'] : '').' — '.$first['reason'] : '.'))
            ->send();
    }

    public function clearResult(): void
    {
        $this->result = null;
    }
}
