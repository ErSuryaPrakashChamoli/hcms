<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Models\FeedbackRound;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\FeedbackRounds;
use App\Domain\Performance\Services\PerformanceWindows;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/**
 * HROne parity GO-2 (matrix 27 §27.1 #6, §27.2.2 #3–9, §27.2.4 #4, §27.3.3 #3–5, §27.4.1 #6): HR's population actions on
 * a running performance cycle — "Add employees to cycle", "Extend window" (HROne "Extend goal" / "Extend review"),
 * "Withdraw from cycle" — and "Extend 360" for chosen people of a 360 round.
 *
 * The people are chosen with the WO-2 filter group (EmployeeFilters): named employees, an organisation unit (company,
 * business unit, division, department with its sub-departments, designation, grade, level, location) or pasted employee
 * codes — always inside the person's access scope (the employee query carries it). Adding and withdrawing run through the
 * WO-1 bulk framework (BulkRuns: bulk.run and performance.manage, one result per person, queued with retries and the
 * tenant bound, a typed count above `peopleos.bulk.confirm_over`); extensions are one service call (PerformanceWindows).
 * Every action is offered only to people holding its rights; the services check them again.
 */
final class PerformanceCycleActions
{
    /** The person may run the bulk operation (bulk.run and its permission). */
    private static function mayRun(string $operation): bool
    {
        $user = auth()->user();
        $registry = app(BulkOperations::class);

        return $user !== null && ($op = $registry->find($operation)) !== null && $registry->allows($user, $op);
    }

    private static function cycleOf(?Model $record, ?int $chosen): ?PerformanceCycle
    {
        return $record instanceof PerformanceCycle ? $record : ($chosen ? PerformanceCycle::query()->find($chosen) : null);
    }

    /** @return array<int, string> running cycles, latest first */
    private static function runningCycles(): array
    {
        return PerformanceCycle::query()->where('status', 'active')->orderByDesc('period_start')->pluck('name', 'id')->all();
    }

    private static function cycleField(): Select
    {
        return Select::make('performance_cycle_id')->label('Performance cycle')->required()->native(false)->live()
            ->options(fn () => self::runningCycles())->default(fn () => array_key_first(self::runningCycles()));
    }

    /** The employees of a cycle still in it (not withdrawn), in the viewer's access scope. */
    public static function inCycle(?PerformanceCycle $cycle): Builder
    {
        return Employee::query()->whereIn('id', Appraisal::query()->where('performance_cycle_id', $cycle?->id ?? 0)->where('status', '!=', 'withdrawn')->select('employee_id'));
    }

    /** The people of a 360 round, in the viewer's access scope. */
    public static function inRound(FeedbackRound $round): Builder
    {
        return Employee::query()->whereIn('id', FeedbackParticipant::query()->where('feedback_round_id', $round->id)->select('employee_id'));
    }

    // --- The population picker (WO-2 filter group) --------------------------------------------------------------------

    /**
     * @param  Closure(Get): Builder  $candidates  the employees who may be chosen (narrowed further by the choice)
     * @return list<mixed>
     */
    public static function population(Closure $candidates, ?string $everyone = null, bool $confirm = false): array
    {
        $options = array_filter([
            'everyone' => $everyone,
            'people' => 'Chosen employees',
            'organisation' => 'An organisation unit',
            'codes' => 'Pasted employee codes',
        ]);
        $who = fn (Get $get) => (string) ($get('who') ?? '');

        return [
            Radio::make('who')->label('Who')->options($options)->default($everyone !== null ? 'everyone' : 'people')->required()->live()->inline(),
            Select::make('employee_ids')->label('Employees')->multiple()->searchable()->live()
                ->visible(fn (Get $get) => $who($get) === 'people')->required(fn (Get $get) => $who($get) === 'people')
                ->getSearchResultsUsing(fn (string $search, Get $get) => self::label($candidates($get)->where(fn (Builder $q) => EmployeeFilters::search()($q, $search))->limit(50)->get()))
                ->getOptionLabelsUsing(fn (array $values, Get $get) => self::label($candidates($get)->whereKey(array_map('intval', $values))->get())),
            Grid::make(2)->visible(fn (Get $get) => $who($get) === 'organisation')->schema(array_values(array_map(
                fn (string $column) => Select::make('organisation.'.$column)->label(EmployeeFilters::ORGANISATION[$column][0])->multiple()->searchable()->preload()->live()
                    ->options(fn () => EmployeeFilters::ORGANISATION[$column][1]::query()->orderBy('name')->limit(1000)->pluck('name', 'id')->all()),
                array_keys(EmployeeFilters::ORGANISATION),
            ))),
            Textarea::make('codes')->label('Employee codes')->rows(3)->live(onBlur: true)->placeholder('e.g. EMP001, EMP002')
                ->helperText('Paste up to '.EmployeeFilters::codesMax().' codes, separated by commas, spaces or new lines.')
                ->visible(fn (Get $get) => $who($get) === 'codes')->required(fn (Get $get) => $who($get) === 'codes'),
            Text::make(fn (Get $get) => self::countLine($candidates, $get))->visible(fn (Get $get) => $who($get) !== 'everyone'),
            TextInput::make('confirm_count')->label(fn (Get $get) => 'Type '.self::count($candidates, $get).' to confirm')
                ->helperText('More than '.BulkRuns::confirmOver().' employees: type the number to confirm. Each one is handled on its own and the result lists every person.')
                ->visible(fn (Get $get) => $confirm && $who($get) !== 'everyone' && BulkRuns::needsTypedCount(self::count($candidates, $get)))
                ->required(fn (Get $get) => $confirm && $who($get) !== 'everyone' && BulkRuns::needsTypedCount(self::count($candidates, $get))),
        ];
    }

    /** @return array<int, string> employee id => "CODE · Name" */
    private static function label(iterable $employees): array
    {
        $out = [];
        foreach ($employees as $employee) {
            $employee->loadMissing('person');
            $out[(int) $employee->id] = trim($employee->employee_code.' · '.($employee->person?->full_name ?? ''), ' ·');
        }

        return $out;
    }

    /** @return array<string, mixed> the picker's state from the form */
    private static function stateFrom(Get $get): array
    {
        return ['who' => $get('who'), 'employee_ids' => $get('employee_ids'), 'organisation' => $get('organisation'), 'codes' => $get('codes')];
    }

    private static function count(Closure $candidates, Get $get): int
    {
        try {
            $ids = self::chosen(self::stateFrom($get), $candidates($get));

            return $ids === null ? 0 : count($ids);
        } catch (Throwable) {
            return 0;
        }
    }

    private static function countLine(Closure $candidates, Get $get): string
    {
        $n = self::count($candidates, $get);

        return $n === 1 ? '1 employee chosen.' : $n.' employees chosen.';
    }

    /**
     * The employees the picker chose among the candidates: null for "everyone", else their ids.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>|null
     */
    public static function chosen(array $data, Builder $candidates): ?array
    {
        $who = (string) ($data['who'] ?? 'people');
        if ($who === 'everyone') {
            return null;
        }
        $query = clone $candidates;
        if ($who === 'people') {
            $ids = array_values(array_filter(array_map('intval', (array) ($data['employee_ids'] ?? []))));
            $query->whereKey($ids ?: [0]);
        } elseif ($who === 'organisation') {
            $chosen = [];
            foreach (array_keys(EmployeeFilters::ORGANISATION) as $column) {
                $ids = array_values(array_filter(array_map('intval', (array) (($data['organisation'] ?? [])[$column] ?? []))));
                if ($ids !== []) {
                    $chosen[$column] = $column === 'department_id' ? EmployeeFilters::withSubDepartments($ids) : $ids;
                }
            }
            if ($chosen === []) {
                throw new RuntimeException('Choose at least one organisation unit.');
            }
            $query->whereHas('currentPosition', function (Builder $p) use ($chosen) {
                foreach ($chosen as $column => $ids) {
                    $p->whereIn($p->getModel()->qualifyColumn($column), $ids);
                }
            });
        } elseif ($who === 'codes') {
            $codes = EmployeeFilters::parseCodes($data['codes'] ?? null);
            if ($codes === []) {
                throw new RuntimeException('Paste at least one employee code.');
            }
            $query->whereIn($query->getModel()->qualifyColumn('employee_code'), $codes);
        } else {
            throw new RuntimeException('Choose who.');
        }

        return $query->orderBy($query->getModel()->qualifyColumn('id'))->limit(BulkRuns::maxRecords() + 1)
            ->pluck($query->getModel()->qualifyColumn('id'))->map(fn ($id) => (int) $id)->all();
    }

    // --- Actions ------------------------------------------------------------------------------------------------------

    /** "Add employees" — on a cycle ($bound: the record) or, unbound, with a cycle to choose. */
    public static function addEmployees(bool $bound = false): Action
    {
        return Action::make($bound ? 'addEmployees' : 'posPerfAddEmployees')->label('Add employees')->icon(Heroicon::OutlinedUserPlus)->color('gray')
            ->visible(fn (?Model $record = null) => self::mayRun('employees.add_to_cycle') && ($bound ? $record instanceof PerformanceCycle && $record->status === 'active' : self::runningCycles() !== []))
            ->modalHeading('Add employees to the cycle')
            ->modalDescription('Each employee gets an appraisal through the launch rules (eligibility of the cycle and the performance policy), the reviews of the stages already open — their reviewers are told — and the goal sheet the policy maps. Employees already in the cycle have their open reviews brought up to date; nobody gets a second appraisal.')
            ->modalSubmitActionLabel('Add')
            ->schema(fn (?Model $record = null) => [
                ...($bound ? [] : [self::cycleField()]),
                ...self::population(fn (Get $get) => Employee::query()->employed(), null, true),
            ])
            ->action(function (array $data, ?Model $record = null) {
                $cycle = self::cycleOf($record, (int) ($data['performance_cycle_id'] ?? 0));
                self::startRun('performance_cycles', 'employees.add_to_cycle', fn () => self::chosen($data, Employee::query()->employed()) ?? [],
                    ['performance_cycle_id' => $cycle?->id], $data);
            });
    }

    /** "Withdraw from cycle" for chosen employees of a cycle (a bulk run over their appraisals, one reason). */
    public static function withdrawEmployees(bool $bound = false): Action
    {
        return Action::make($bound ? 'withdrawEmployees' : 'posPerfWithdraw')->label('Withdraw from cycle')->icon(Heroicon::OutlinedArrowLeftStartOnRectangle)->color('danger')
            ->visible(fn (?Model $record = null) => self::mayRun('appraisals.withdraw') && ($bound ? $record instanceof PerformanceCycle && $record->status === 'active' : self::runningCycles() !== []))
            ->modalHeading('Withdraw employees from the cycle')
            ->modalDescription('Each chosen appraisal leaves the cycle: its pending reviews are withdrawn and the employee\'s open goals and pending goal sheet of the cycle are cancelled with the reason. Nothing is deleted; finalized appraisals are refused and stay as they are.')
            ->modalSubmitActionLabel('Withdraw')
            ->schema(fn (?Model $record = null) => [
                ...($bound ? [] : [self::cycleField()]),
                ...self::population(fn (Get $get) => self::inCycle(self::cycleOf($record, (int) $get('performance_cycle_id'))), null, true),
                Textarea::make('reason')->label('Why are they withdrawn from the cycle?')->required()->maxLength(500),
            ])
            ->action(function (array $data, ?Model $record = null) {
                $cycle = self::cycleOf($record, (int) ($data['performance_cycle_id'] ?? 0));
                self::startRun('appraisals', 'appraisals.withdraw', function () use ($data, $cycle) {
                    $employees = self::chosen($data, self::inCycle($cycle)) ?? [];

                    return Appraisal::query()->where('performance_cycle_id', $cycle?->id ?? 0)->whereIn('employee_id', $employees ?: [0])->orderBy('id')->pluck('id')->all();
                }, ['reason' => (string) ($data['reason'] ?? '')], $data);
            });
    }

    /**
     * "Extend window" (HROne "Extend goal" with $stage goal_setting, "Extend review" with a review stage): a stage of a
     * running cycle for everyone or for chosen employees, with a new end date and a reason.
     */
    public static function extendWindow(?string $stage = null, bool $bound = false, ?string $label = null): Action
    {
        $stages = fn (?PerformanceCycle $cycle) => $cycle === null ? [] : collect(PerformanceWindows::STAGES)
            ->filter(fn (string $key) => $cycle->hasStage($key) && PerformanceWindows::baseEnd($cycle, $key) !== null)
            ->mapWithKeys(fn (string $key) => [$key => PerformanceWindows::stageLabel($key).' (ends '.app(PerformanceWindows::class)->stageEnd($cycle, $key)?->format('d M Y').')'])->all();
        $defaultStage = function (?PerformanceCycle $cycle) use ($stage, $stages): ?string {
            $available = array_keys($stages($cycle));
            if ($stage !== null && $stage !== 'review') {
                return in_array($stage, $available, true) ? $stage : null;
            }
            // "Extend review": the review stage the cycle is in, else the first review stage.
            $current = $cycle?->current_stage;

            return in_array($current, $available, true) && $current !== 'goal_setting' ? $current : collect($available)->first(fn ($k) => $k !== 'goal_setting');
        };

        return Action::make($bound ? 'extendWindow' : 'posPerfExtend'.ucfirst((string) ($stage === 'goal_setting' ? 'goal' : 'review')))
            ->label($label ?? ($bound ? 'Extend window' : ($stage === 'goal_setting' ? 'Extend goal' : 'Extend review')))->icon(Heroicon::OutlinedCalendarDays)->color('gray')
            ->visible(fn (?Model $record = null) => (auth()->user()?->can('performance.manage') ?? false) && ($bound ? $record instanceof PerformanceCycle && $record->status === 'active' : self::runningCycles() !== []))
            ->modalHeading(fn () => $stage === 'goal_setting' ? 'Extend the goal-setting window' : 'Extend a window')
            ->modalDescription('More time for one stage, for everyone in the cycle or for chosen employees. The stage\'s own dates stay as they are; the extension is kept with its reason. Goal setting, reminders, review requests and My tasks follow the new date.')
            ->modalSubmitActionLabel('Extend')
            ->schema(fn (?Model $record = null) => [
                ...($bound ? [] : [self::cycleField()]),
                Select::make('stage')->label('Window')->required()->native(false)
                    ->options(fn (Get $get) => $stages(self::cycleOf($record, (int) $get('performance_cycle_id'))))
                    ->default(fn (Get $get) => $defaultStage(self::cycleOf($record, (int) $get('performance_cycle_id')))),
                ...self::population(fn (Get $get) => self::inCycle(self::cycleOf($record, (int) $get('performance_cycle_id'))), 'Everyone in the cycle'),
                DatePicker::make('ends_on')->label('New end date')->required()->native(false)->minDate(today()),
                Textarea::make('reason')->label('Why is the window extended?')->required()->maxLength(500),
            ])
            ->action(function (array $data, ?Model $record = null) {
                $cycle = self::cycleOf($record, (int) ($data['performance_cycle_id'] ?? 0));
                PerformanceActions::run(function () use ($cycle, $data) {
                    if ($cycle === null) {
                        throw new RuntimeException('Choose a running performance cycle.');
                    }
                    $ids = self::chosen($data, self::inCycle($cycle));
                    if ($ids === []) {
                        throw new RuntimeException('Nobody in the cycle matches your choice.');
                    }

                    return app(PerformanceWindows::class)->extend($cycle, (string) ($data['stage'] ?? ''), $ids, (string) $data['ends_on'], (string) ($data['reason'] ?? ''), auth()->user());
                }, fn (array $result) => 'Window extended: '.PerformanceWindows::summary($result));
            });
    }

    /** A cycle's own withdraw action on one appraisal (the appraisal page): synchronous, one reason. */
    public static function withdrawAppraisal(): Action
    {
        return Action::make('withdrawFromCycle')->label('Withdraw from cycle')->icon(Heroicon::OutlinedArrowLeftStartOnRectangle)->color('danger')
            ->visible(fn (Appraisal $record) => (auth()->user()?->can('performance.manage') ?? false) && ! $record->isFinal() && ! $record->isWithdrawn() && ! $record->isLocked()
                && $record->cycle?->status === 'active' && ! AppraisalVisibility::isOwn($record, auth()->user()))
            ->modalHeading('Withdraw from the cycle')
            ->modalDescription('The appraisal leaves the cycle: its pending reviews are withdrawn and the employee\'s open goals and pending goal sheet of the cycle are cancelled with the reason. Nothing is deleted; the employee and their manager are told.')
            ->schema([Textarea::make('reason')->label('Why is the employee withdrawn from the cycle?')->required()->maxLength(500)])
            ->action(fn (Appraisal $record, array $data) => PerformanceActions::run(fn () => app(Appraisals::class)->withdraw($record, (string) $data['reason'], auth()->user()), 'Withdrawn from the cycle'));
    }

    /**
     * The 360 round's "Extend window" with a choice of whom (HROne "Extend 360": specific employees / organisation-wise /
     * bulk): everyone moves the round's window (as before); chosen people get their own later date.
     */
    public static function extendRound(): Action
    {
        return Action::make('extend')->label('Extend window')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
            ->visible(fn (FeedbackRound $record) => $record->isOpen() && FeedbackRounds::mayManage(auth()->user()))
            ->schema(fn (FeedbackRound $record) => [
                ...self::population(fn (Get $get) => self::inRound($record), 'Everyone in the round'),
                DatePicker::make('closes_on')->label('New closing date')->required()->native(false),
                Textarea::make('reason')->required()->maxLength(500),
            ])
            ->action(fn (FeedbackRound $record, array $data) => PerformanceActions::run(function () use ($record, $data) {
                $ids = self::chosen($data, self::inRound($record));
                if ($ids === []) {
                    throw new RuntimeException('Nobody in the round matches your choice.');
                }

                return app(PerformanceWindows::class)->extendRound($record, $ids, (string) $data['closes_on'], (string) $data['reason'], auth()->user());
            }, fn (array $result) => ($data['who'] ?? 'everyone') === 'everyone' ? 'Window extended' : 'Window extended: '.PerformanceWindows::summary($result)));
    }

    /**
     * Start a WO-1 bulk run over the ids $ids() gives (resolved when the form is submitted) and tell the person.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $data  the form (its typed count)
     */
    private static function startRun(string $register, string $operation, Closure $ids, array $input, array $data): void
    {
        try {
            $ids = array_values($ids());
            if ($ids === []) {
                throw new RuntimeException('Nobody matches your choice.');
            }
            $confirmed = isset($data['confirm_count']) && is_numeric($data['confirm_count']) ? (int) $data['confirm_count'] : null;
            $run = app(BulkRuns::class)->start(auth()->user(), $register, $operation, $ids, $input, 'all_matching', BulkRuns::needsTypedCount(count($ids)) ? $confirmed : null);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not started')->body($e->getMessage())->persistent()->send();

            return;
        }
        $run->refresh();
        $open = Action::make('open')->label('See each person')->url(BulkRunResource::getUrl('view', ['record' => $run]));
        $notification = $run->isFinished()
            ? Notification::make()->title($run->operation_label.': '.$run->summary())->body($run->refused + $run->failed > 0 ? 'Some were not done; the run lists each one with the reason.' : 'Done for everyone chosen.')
            : Notification::make()->title($run->operation_label.' queued for '.$run->total.' '.($run->total === 1 ? 'person' : 'people'))->body('You will be notified with the result.');
        $notification->status($run->status === 'failed' ? 'danger' : ($run->refused + $run->failed > 0 ? 'warning' : 'success'))->actions([$open])->send();
    }
}
