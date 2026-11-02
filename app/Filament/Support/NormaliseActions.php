<?php

namespace App\Filament\Support;

use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Services\PerformanceDistribution;
use App\Domain\Performance\Services\PeriodicReviews;
use App\Domain\Performance\Services\RatingNormalisation;
use App\Domain\Performance\Support\RatingRules;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

/**
 * HROne parity GO-3 (matrix 27 §27.3.4 #2–4, §27.1 #6): the normalise grids — HROne "Normalize performance rating" and
 * "Normalize review rating" — as step wizards:
 *
 * 1. Who: the WO-2 population picker (PerformanceCycleActions::population: everyone on the board / the team, chosen
 *    employees found with the shared search — HROne's "Search team member" —, an organisation unit, or pasted codes),
 *    always among the candidates the screen gives (the board's population in the person's access scope, or the
 *    manager's own team); for review ratings, the review period first.
 * 2. Ratings: one row per person the viewer may normalise — employee name, code, the system-calculated rating (the review
 *    ratings: the reviewer's), the normalised rating so far and the new rating; alphabetical, never by rating. People left
 *    out are counted with the reason (finalized, one's own …).
 * 3. Reason: one reason for the batch (and the typed count of a large batch).
 *
 * Only rows whose rating changes are sent. Up to `peopleos.bulk.confirm_over` changes are applied at once (one audit
 * operation; refusals listed; the distribution check's warning shown, HP-8g); more run as a WO-1 bulk run (bulk.run, the
 * typed count, one result per row on People → Bulk runs). Managers normalising their team (PER-2 / D-GO2 on) are always
 * applied at once. The services decide every row again: the grid is never trusted.
 */
final class NormaliseActions
{
    /**
     * "Normalise ratings" for a cycle.
     *
     * @param  Closure(): ?PerformanceCycle  $cycle
     * @param  Closure(): Builder  $candidates  the employees who may be chosen (an Employee query)
     */
    public static function ratings(string $name, Closure $cycle, Closure $candidates, string $everyone, ?Closure $visible = null): Action
    {
        return Action::make($name)->label($name === 'normaliseTeam' ? 'Normalise my team\'s ratings' : 'Normalise ratings')
            ->icon(Heroicon::OutlinedAdjustmentsVertical)->color('warning')
            ->visible(fn () => ($c = $cycle()) !== null && $c->status === 'active' && app(RatingNormalisation::class)->mayNormalise(auth()->user()) && ($visible === null || $visible()))
            ->modalHeading('Normalise performance ratings')
            ->modalDescription('Choose the people, enter the normalised rating of each, and give one reason for the batch. Each rating goes through the calibration rules and is kept in the calibration history and the audit trail.')
            ->modalWidth('5xl')
            ->steps([
                Step::make('Who')->description('Search team member, an organisation unit or pasted codes')
                    ->schema(PerformanceCycleActions::population(fn (Get $get) => $candidates(), $everyone))
                    ->afterValidation(function (Get $get, Set $set) use ($cycle, $candidates) {
                        $c = $cycle() ?? self::halt('Choose a running performance cycle.');
                        $grid = app(RatingNormalisation::class)->grid($c, self::employeeIds($get, $candidates), auth()->user());
                        self::fillGrid($set, $grid, 'appraisal_id');
                    }),
                Step::make('Ratings')->description('Enter the normalised rating of each person to change')
                    ->schema(self::gridFields('appraisal_id', fn () => $cycle(), 'System calculated rating')),
                Step::make('Reason')->description('One reason for the batch')
                    ->schema(self::reasonFields('Why are these ratings normalised?', fn (Get $get) => self::changes($get('rows'), 'appraisal_id'), fn () => ! app(RatingNormalisation::class)->asManager(auth()->user()))),
            ])
            ->action(function (array $data) use ($cycle, $candidates) {
                $c = $cycle();
                $user = auth()->user();
                try {
                    if ($c === null) {
                        throw new RuntimeException('Choose a running performance cycle.');
                    }
                    // The grid is never trusted: only appraisals of the cycle among the people chosen from the candidates.
                    $allowed = Appraisal::query()->where('performance_cycle_id', $c->id)->where('status', '!=', 'withdrawn')
                        ->whereIn('employee_id', self::employeeIds(fn (string $key) => $data[$key] ?? null, $candidates) ?: [0])->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $changes = array_intersect_key(self::changes($data['rows'] ?? [], 'appraisal_id'), array_flip($allowed));
                    if ($changes === []) {
                        throw new RuntimeException('No rating was changed.');
                    }
                    $reason = RatingNormalisation::reason($data['reason'] ?? null);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not normalised')->body($e->getMessage())->persistent()->send();

                    return;
                }
                $labels = self::labels($data['rows'] ?? [], 'appraisal_id');
                if (! app(RatingNormalisation::class)->asManager($user) && BulkRuns::needsTypedCount(count($changes))) {
                    self::startRun('appraisals.normalise', $changes, $reason, $data);

                    return;
                }
                PerformanceActions::run(fn () => app(RatingNormalisation::class)->apply($c, $changes, $reason, $user),
                    fn (array $result) => self::report($result, $labels, 'rating(s) normalised'));
            });
    }

    /**
     * "Normalise review ratings" (HP-8i periodic reviews) for a cycle: one review period per batch.
     *
     * @param  Closure(): ?PerformanceCycle  $cycle
     * @param  Closure(): Builder  $candidates
     */
    public static function reviewRatings(Closure $cycle, Closure $candidates, string $everyone): Action
    {
        return Action::make('normaliseReviews')->label('Normalise review ratings')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('gray')
            ->visible(fn () => ($c = $cycle()) !== null && $c->status === 'active' && (auth()->user()?->can('performance.calibrate') ?? false)
                && PerformancePeriodReview::query()->where('performance_cycle_id', $c->id)->where('status', 'submitted')->exists())
            ->modalHeading('Normalise periodic review ratings')
            ->modalDescription('Choose a review period and the people, enter the normalised rating of each, and give one reason. The reviewer\'s own rating is kept; the normalised one is what the performance policy rolls up into the appraisal.')
            ->modalWidth('5xl')
            ->steps([
                Step::make('Review period')->description('The review cycle and the people')
                    ->schema([
                        Select::make('sequence')->label('Review cycle')->required()->native(false)
                            ->options(fn () => ($c = $cycle()) === null ? [] : app(PeriodicReviews::class)->periodsToNormalise($c)),
                        ...PerformanceCycleActions::population(fn (Get $get) => $candidates(), $everyone),
                    ])
                    ->afterValidation(function (Get $get, Set $set) use ($cycle, $candidates) {
                        $c = $cycle() ?? self::halt('Choose a running performance cycle.');
                        $grid = app(PeriodicReviews::class)->normaliseGrid($c, (int) $get('sequence'), self::employeeIds($get, $candidates), auth()->user());
                        self::fillGrid($set, $grid, 'review_id');
                    }),
                Step::make('Ratings')->description('Enter the normalised rating of each review to change')
                    ->schema(self::gridFields('review_id', fn () => $cycle(), 'Review rating')),
                Step::make('Reason')->description('One reason for the batch')
                    ->schema(self::reasonFields('Why are these review ratings normalised?', fn (Get $get) => self::changes($get('rows'), 'review_id'), fn () => true)),
            ])
            ->action(function (array $data) use ($cycle, $candidates) {
                $c = $cycle();
                $sequence = (int) ($data['sequence'] ?? 0);
                try {
                    if ($c === null) {
                        throw new RuntimeException('Choose a running performance cycle.');
                    }
                    $allowed = PerformancePeriodReview::query()->where('performance_cycle_id', $c->id)->where('sequence', $sequence)
                        ->whereIn('employee_id', self::employeeIds(fn (string $key) => $data[$key] ?? null, $candidates) ?: [0])->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $changes = array_intersect_key(self::changes($data['rows'] ?? [], 'review_id'), array_flip($allowed));
                    if ($changes === []) {
                        throw new RuntimeException('No rating was changed.');
                    }
                    $reason = RatingNormalisation::reason($data['reason'] ?? null);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not normalised')->body($e->getMessage())->persistent()->send();

                    return;
                }
                if (BulkRuns::needsTypedCount(count($changes))) {
                    self::startRun('period_reviews.normalise', $changes, $reason, $data);

                    return;
                }
                $labels = self::labels($data['rows'] ?? [], 'review_id');
                PerformanceActions::run(fn () => app(PeriodicReviews::class)->normaliseBatch($c, $sequence, $changes, $reason, auth()->user()),
                    fn (array $result) => self::report($result, $labels, 'review rating(s) normalised'));
            });
    }

    // --- Pieces -------------------------------------------------------------------------------------------------------

    /**
     * The employees the picker chose among the candidates (everyone = all candidates).
     *
     * @param  Get|Closure(string): mixed  $get
     * @return list<int>
     */
    public static function employeeIds(Get|Closure $get, Closure $candidates): array
    {
        $state = ['who' => $get('who'), 'employee_ids' => $get('employee_ids'), 'organisation' => $get('organisation'), 'codes' => $get('codes')];
        $query = $candidates();

        return PerformanceCycleActions::chosen($state, $query) ?? (clone $query)->orderBy($query->getModel()->qualifyColumn('id'))
            ->limit(BulkRuns::maxRecords() + 1)->pluck($query->getModel()->qualifyColumn('id'))->map(fn ($id) => (int) $id)->all();
    }

    public static function gridMax(): int
    {
        return max(1, (int) config('peopleos.performance.normalise_grid_max', 500));
    }

    /** @param  array{rows: list<array<string, mixed>>, skipped: array<string, int>}  $grid */
    private static function fillGrid(Set $set, array $grid, string $key): void
    {
        if (count($grid['rows']) > self::gridMax()) {
            self::halt(count($grid['rows']).' people to normalise: at most '.self::gridMax().' fit in one grid. Choose an organisation unit or paste codes to narrow it.');
        }
        if ($grid['rows'] === []) {
            self::halt('Nobody you chose has a rating you may normalise'.($grid['skipped'] === [] ? '.' : ': '.self::skippedLine($grid['skipped'])));
        }
        $format = fn (?float $v) => $v === null ? '—' : PerformanceDistribution::format($v);
        $set('rows', collect($grid['rows'])->mapWithKeys(fn (array $row) => ['row'.$row[$key] => [
            $key => $row[$key], 'expected' => $row['expected'], 'employee' => (string) $row['employee'], 'code' => (string) $row['code'],
            'system' => $format($row['system']), 'normalised' => $format($row['normalised']), 'rating' => null,
        ]])->all());
        $set('skipped', $grid['skipped'] === [] ? null : 'Not in the grid: '.self::skippedLine($grid['skipped']));
    }

    /** @param  array<string, int>  $skipped */
    private static function skippedLine(array $skipped): string
    {
        return collect($skipped)->map(fn (int $n, string $reason) => $n.' — '.rtrim($reason, '.'))->implode('; ').'.';
    }

    /** @return list<mixed> the grid step */
    private static function gridFields(string $key, Closure $cycle, string $systemLabel): array
    {
        $scale = fn () => $cycle()?->ratingScale();

        return [
            Text::make(fn (Get $get) => (string) ($get('skipped') ?? ''))->visible(fn (Get $get) => filled($get('skipped'))),
            Hidden::make('skipped'),
            Repeater::make('rows')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)
                ->table([
                    TableColumn::make('Employee name'),
                    TableColumn::make('Employee code'),
                    TableColumn::make($systemLabel),
                    TableColumn::make('Normalised rating'),
                    TableColumn::make('New normalised rating'),
                ])
                ->schema([
                    Hidden::make($key),
                    Hidden::make('expected'),
                    TextInput::make('employee')->hiddenLabel()->disabled()->dehydrated(false),
                    TextInput::make('code')->hiddenLabel()->disabled()->dehydrated(false),
                    TextInput::make('system')->hiddenLabel()->disabled()->dehydrated(false),
                    TextInput::make('normalised')->hiddenLabel()->disabled()->dehydrated(false),
                    self::ratingInput($scale()),
                ]),
        ];
    }

    /** The rating input: a level of the scale (no decimals, as the calibrate form) or a number with the organisation's decimals. */
    private static function ratingInput(mixed $scale): Select|TextInput
    {
        if ($scale === null || RatingRules::decimals() === 0) {
            return Select::make('rating')->hiddenLabel()->placeholder('No change')->native(false)->options($scale?->options() ?? []);
        }

        return TextInput::make('rating')->hiddenLabel()->placeholder('No change')->numeric()->step(RatingRules::decimals() === 1 ? 0.1 : 0.01)
            ->minValue($scale->min())->maxValue($scale->max());
    }

    /** @return list<mixed> the reason step (and the typed count above the bulk threshold, when the batch may run in bulk) */
    private static function reasonFields(string $label, Closure $changes, Closure $mayRunInBulk): array
    {
        $count = fn (Get $get) => count($changes($get));
        $bulk = fn (Get $get) => $mayRunInBulk() && BulkRuns::needsTypedCount($count($get));

        return [
            Text::make(fn (Get $get) => $count($get) === 1 ? '1 rating changes.' : $count($get).' ratings change.'),
            Textarea::make('reason')->label($label)->required()->maxLength(500)->rows(3),
            // Its own name: the population picker of step 1 has a (hidden) confirm_count of its own.
            TextInput::make('confirm_changes')->label(fn (Get $get) => 'Type '.$count($get).' to confirm')
                ->helperText('More than '.BulkRuns::confirmOver().' changes run as a bulk run: each is handled on its own and the run lists every person with the result.')
                ->visible($bulk)->required($bulk),
        ];
    }

    /**
     * The rows whose rating changes: id => ['rating' => …, 'expected' => …] (blank or unchanged rows left out).
     *
     * @return array<int, array{rating: float, expected: ?float}>
     */
    public static function changes(mixed $rows, string $key): array
    {
        $changes = [];
        foreach ((array) $rows as $row) {
            $id = (int) ($row[$key] ?? 0);
            $rating = RatingNormalisation::number($row['rating'] ?? null);
            $expected = RatingNormalisation::number($row['expected'] ?? null);
            if ($id > 0 && $rating !== null && ($expected === null || abs($rating - $expected) > 0.0001)) {
                $changes[$id] = ['rating' => $rating, 'expected' => $expected];
            }
        }

        return $changes;
    }

    /** @return array<int, string> id => "CODE · Name" (for the refusals) */
    private static function labels(mixed $rows, string $key): array
    {
        $labels = [];
        foreach ((array) $rows as $row) {
            $labels[(int) ($row[$key] ?? 0)] = trim(($row['code'] ?? '').' · '.($row['employee'] ?? ''), ' ·');
        }

        return $labels;
    }

    /**
     * The success title; refusals (and the distribution warning, HP-8g) as their own notifications.
     *
     * @param  array{done: list<int>, refused: array<int, string>, warning?: ?string}  $result
     * @param  array<int, string>  $labels
     */
    private static function report(array $result, array $labels, string $what): string
    {
        if ($result['refused'] !== []) {
            Notification::make()->warning()->title(count($result['refused']).' not normalised')->persistent()
                ->body(collect($result['refused'])->take(10)->map(fn (string $reason, int $id) => (($labels[$id] ?? '') ?: '#'.$id).': '.$reason)->implode("\n"))->send();
        }
        if (filled($result['warning'] ?? null)) {
            Notification::make()->warning()->title('Rating distribution outside the targets')->body($result['warning'])->persistent()->send();
        }

        return count($result['done']).' '.$what;
    }

    /**
     * A large batch as a WO-1 bulk run: the ratings travel as the run's input (kept out of the audit trail).
     *
     * @param  array<int, array{rating: float, expected: ?float}>  $changes
     * @param  array<string, mixed>  $data
     */
    private static function startRun(string $operation, array $changes, string $reason, array $data): void
    {
        try {
            $user = auth()->user();
            $registry = app(BulkOperations::class);
            if (! $user instanceof User || ($op = $registry->find($operation)) === null || ! $registry->allows($user, $op)) {
                throw new RuntimeException('More than '.BulkRuns::confirmOver().' changes run as a bulk run, which you cannot start (bulk.run). Normalise fewer at a time.');
            }
            $confirmed = isset($data['confirm_changes']) && is_numeric($data['confirm_changes']) ? (int) $data['confirm_changes'] : null;
            $run = app(BulkRuns::class)->start($user, 'calibration_board', $operation, array_keys($changes), [
                'reason' => $reason,
                'ratings' => array_map(fn (array $c) => $c['rating'], $changes),
                'expected' => array_map(fn (array $c) => $c['expected'], $changes),
            ], 'selected', $confirmed);
        } catch (Throwable $e) {
            if (! $e instanceof RuntimeException) {
                report($e);
            }
            Notification::make()->danger()->title('Not started')->body($e->getMessage())->persistent()->send();

            return;
        }
        $run->refresh();
        $open = Action::make('open')->label('See each person')->url(BulkRunResource::getUrl('view', ['record' => $run]));
        $notification = $run->isFinished()
            ? Notification::make()->title($run->operation_label.': '.$run->summary())->body($run->refused + $run->failed > 0 ? 'Some were not done; the run lists each one with the reason.' : 'Done for everyone changed.')
            : Notification::make()->title($run->operation_label.' queued for '.$run->total.' '.($run->total === 1 ? 'person' : 'people'))->body('You will be notified with the result.');
        $notification->status($run->status === 'failed' ? 'danger' : ($run->refused + $run->failed > 0 ? 'warning' : 'success'))->actions([$open])->send();
    }

    private static function halt(string $message): never
    {
        Notification::make()->danger()->title('Cannot continue')->body($message)->persistent()->send();

        throw new Halt;
    }
}
