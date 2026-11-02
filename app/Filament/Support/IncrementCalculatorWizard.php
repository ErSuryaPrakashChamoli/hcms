<?php

namespace App\Filament\Support;

use App\Domain\Compensation\Models\CompensationBudget;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Domain\Compensation\Services\IncrementCalculator;
use App\Domain\Compensation\Services\IncrementPlanSharing;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Filament\Resources\CompensationCycles\CompensationCycleResource;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * HROne parity PO-17 (matrix 25 §25.9.2): the increment calculator's screens — the "Create new" / "Clone" wizard
 * (Population → Basis & proration → Grid → Review), "Share with managers" and the error file. Every write goes through
 * CompensationCycles / IncrementPlanSharing; the wizard's Review is the same calculation, run without writing.
 */
final class IncrementCalculatorWizard
{
    /** The population's organisation filters (position on the effective date) and their option sources. */
    private const ORGANISATION = [
        'business_unit_id' => 'business_unit_id', 'division_id' => 'division_id', 'department_id' => 'department_id', 'designation_id' => 'designation_id',
        'grade_id' => 'grade_id', 'level_id' => 'level_id', 'location_id' => 'location_id',
    ];

    public static function mayPlan(): bool
    {
        return (bool) auth()->user()?->hasPermission('compensation.cycles');
    }

    // --- Actions ------------------------------------------------------------------------------------------------------

    /** "Create new" — opens directly with `?action=create` on the Increment calculator page. */
    public static function createAction(): Action
    {
        return Action::make('create')->label('Create new')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => self::mayPlan())
            ->modalHeading('Increment calculator')->modalWidth(Width::FiveExtraLarge)->modalSubmitActionLabel('Create plan')
            ->fillForm(fn () => self::defaults())
            ->steps(fn () => self::steps())
            ->action(function (Action $action, array $data) {
                $cycle = self::run(fn () => app(CompensationCycles::class)->create(self::serviceData($data), auth()->user()), $data);
                if ($cycle !== null) {
                    $action->redirect(CompensationCycleResource::getUrl('view', ['record' => $cycle]));
                }
            });
    }

    /** "Clone": the same wizard, filled from the plan, creating a new draft plan (no lines, shares or proposals). */
    public static function cloneAction(): Action
    {
        return Action::make('clone')->label('Clone')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
            ->visible(fn (CompensationCycle $record) => self::mayPlan() && ! $record->isUpload())
            ->modalHeading(fn (CompensationCycle $record) => 'Clone '.$record->code)->modalWidth(Width::FiveExtraLarge)->modalSubmitActionLabel('Create plan')
            ->fillForm(fn (CompensationCycle $record) => self::fillFrom($record))
            ->steps(fn () => self::steps())
            ->action(function (Action $action, CompensationCycle $record, array $data) {
                $cycle = self::run(fn () => app(CompensationCycles::class)->clone($record, self::serviceData($data), auth()->user()), $data, 'Plan cloned as a draft');
                if ($cycle !== null) {
                    $action->redirect(CompensationCycleResource::getUrl('view', ['record' => $cycle]));
                }
            });
    }

    /** "Share with" (§25.9.2 #9): managers who hold compensation.team and manage someone in the plan. */
    public static function shareAction(): Action
    {
        return Action::make('share')->label('Share with managers')->icon(Heroicon::OutlinedShare)->color('gray')
            ->visible(fn (CompensationCycle $record) => self::mayPlan() && ! $record->isUpload() && ! in_array($record->status, ['rejected', 'cancelled'], true) && $record->employee_count > 0)
            ->modalDescription('Each manager sees only the people they manage in this plan (never their own line) and can propose amounts for them while it is a draft, within the grid and within what the plan gives their team. You accept or decline each proposal.')
            ->schema(fn (CompensationCycle $record) => [
                Select::make('share_with')->label('Share with')->multiple()->searchable()->placeholder('Please select employee(s)')
                    ->options(fn () => app(IncrementPlanSharing::class)->managersFor($record)->reject(fn ($u) => app(IncrementPlanSharing::class)->isSharedWith($record, $u))->pluck('name', 'id')->all())
                    ->helperText('Only people with compensation.team who manage someone in this plan are listed.'),
                CheckboxList::make('stop_sharing')->label('Stop sharing with')
                    ->options(fn () => app(IncrementPlanSharing::class)->sharesOf($record)->mapWithKeys(fn ($s) => [$s->user_id => $s->user?->name ?? '#'.$s->user_id])->all())
                    ->visible(fn () => app(IncrementPlanSharing::class)->sharesOf($record)->isNotEmpty()),
            ])
            ->action(function (CompensationCycle $record, array $data) {
                $sharing = app(IncrementPlanSharing::class);
                try {
                    foreach (array_map('intval', (array) ($data['stop_sharing'] ?? [])) as $userId) {
                        $sharing->revoke($record, $userId, auth()->user());
                    }
                    $results = $sharing->share($record, array_map('intval', (array) ($data['share_with'] ?? [])), auth()->user());
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not possible')->body($e->getMessage())->persistent()->send();

                    return;
                }
                $shared = count(array_filter($results, fn ($r) => $r === true));
                $refused = array_filter($results, fn ($r) => $r !== true);
                Notification::make()->success()->title($shared > 0 ? 'Successful! Increment details have been shared' : 'Sharing updated')
                    ->body($refused === [] ? null : 'Not shared: '.implode(' ', $refused))->send();
            });
    }

    /** "DOWNLOAD ERROR EXCEL" (§25.9.2 #8): the employees the last calculation skipped and why. */
    public static function errorFileAction(): Action
    {
        return Action::make('errorFile')->label('Download error file')->icon(Heroicon::OutlinedExclamationTriangle)->color('warning')
            ->visible(fn (CompensationCycle $record) => self::mayPlan() && count((array) ($record->population_errors ?? [])) > 0)
            ->action(fn (CompensationCycle $record) => self::errorFile($record));
    }

    public static function errorFile(CompensationCycle $record): ?StreamedResponse
    {
        try {
            $csv = app(IncrementCalculator::class)->errorCsv($record, auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not possible')->body($e->getMessage())->send();

            return null;
        }

        return response()->streamDownload(fn () => print ($csv), 'increment-plan-'.strtolower($record->code).'-skipped.csv', ['Content-Type' => 'text/csv']);
    }

    /** Create (and calculate) a plan; tell the person; null when refused. */
    private static function run(callable $create, array $data, string $done = 'Plan created as a draft'): ?CompensationCycle
    {
        try {
            $cycle = $create();
            $result = ($data['populate_now'] ?? true) ? app(CompensationCycles::class)->populate($cycle, auth()->user()) : null;
            Notification::make()->success()->title($done)
                ->body($result === null ? 'Calculate it from the plan when you are ready.' : "{$result['lines']} line(s) calculated, {$result['skipped']} skipped".($result['skipped'] > 0 ? ' (see the error file).' : '.'))->send();

            return $cycle;
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not possible')->body($e->getMessage())->persistent()->send();

            return null;
        }
    }

    // --- The wizard ---------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'cycle_type' => 'annual_increment', 'company_id' => Company::query()->orderBy('name')->value('id'),
            'increment_basis' => 'ctc_percent', 'default_increase_percent' => 0, 'prorate_on_tenure' => false, 'proration_basis' => 'joining',
            'proration_full_months' => 12, 'bands' => [], 'populate_now' => true,
        ];
    }

    /** @return array<string, mixed> the wizard filled from an existing plan (Clone) */
    public static function fillFrom(CompensationCycle $cycle): array
    {
        $population = (array) ($cycle->population ?? []);
        $grid = $cycle->hasGrid() ? (array) $cycle->rating_increase_percent['bands'] : [];

        return [
            'code' => '', 'name' => 'Copy of '.$cycle->name, 'cycle_type' => $cycle->cycle_type, 'company_id' => $cycle->company_id,
            'effective_from' => $cycle->effective_from?->toDateString(),
            'organisation' => (array) ($population['organisation'] ?? []),
            'codes' => implode(', ', (array) ($population['codes'] ?? [])), 'exclude_codes' => implode(', ', (array) ($population['exclude_codes'] ?? [])),
            'increment_basis' => $cycle->increment_basis ?: 'ctc_percent', 'compensation_budget_id' => $cycle->compensation_budget_id, 'performance_cycle_id' => $cycle->performance_cycle_id,
            'default_increase_percent' => (float) $cycle->default_increase_percent, 'default_increase_amount' => $cycle->default_increase_amount !== null ? (float) $cycle->default_increase_amount : null,
            'prorate_on_tenure' => (bool) $cycle->prorate_on_tenure, 'proration_basis' => $cycle->proration_basis ?? 'joining',
            'proration_cutoff' => $cycle->proration_cutoff?->toDateString(), 'proration_full_months' => $cycle->proration_full_months ?? 12,
            'legacy_matrix' => $cycle->hasGrid() ? null : $cycle->rating_increase_percent,
            'bands' => array_map(fn (array $b) => ['ctc_from' => $b['ctc_from'], 'ctc_to' => $b['ctc_to'], 'value' => $b['value'], 'ratings' => $b['ratings']], $grid),
            'populate_now' => true,
        ];
    }

    /** @return list<Step> */
    public static function steps(): array
    {
        $amount = fn (Get $get) => $get('increment_basis') === 'amount';
        $prorate = fn (Get $get) => (bool) $get('prorate_on_tenure');

        return [
            Step::make('Population')->description('The plan and who is in it')->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('code')->label('Plan code')->required()->maxLength(40),
                    TextInput::make('name')->label('Plan name')->required()->maxLength(255),
                    Select::make('cycle_type')->label('Type')->required()->native(false)
                        ->options(collect(config('peopleos.compensation.cycle_types'))->reject(fn ($t) => $t['upload'] ?? false)->map(fn ($t) => $t['label'])->all()),
                    Select::make('company_id')->label('Company')->required()->native(false)->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                    DatePicker::make('effective_from')->label('Increments effective from')->required()->native(false),
                ]),
                Text::make('Who is in the plan: everyone of the company in a state that takes part in cycles, in your access, narrowed by any filter below. Their position on the effective date is used.'),
                Grid::make(['default' => 1, 'md' => 2])->schema(array_values(array_map(
                    fn (string $column) => Select::make('organisation.'.$column)->label(EmployeeFilters::ORGANISATION[$column][0])->multiple()->searchable()->preload()
                        ->options(fn () => EmployeeFilters::ORGANISATION[$column][1]::query()->orderBy('name')->limit(1000)->pluck('name', 'id')->all()),
                    array_keys(self::ORGANISATION),
                ))),
                Select::make('organisation.employment_type_id')->label('Employment type')->multiple()->preload()
                    ->options(fn () => EmploymentType::query()->orderBy('name')->limit(1000)->pluck('name', 'id')->all()),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    Textarea::make('codes')->label('Add bulk employees (codes)')->rows(3)->placeholder('e.g. EMP001, EMP002')
                        ->helperText('Only these employees (up to '.EmployeeFilters::codesMax().' codes, separated by commas, spaces or new lines). Codes that do not match go to the error file.'),
                    Textarea::make('exclude_codes')->label('Leave out these employee codes')->rows(3)->placeholder('e.g. EMP003'),
                ]),
            ]),
            Step::make('Basis & proration')->description('Budget, ratings, amount basis and tenure')->schema([
                Radio::make('increment_basis')->label('On what basis do you want to give the increment?')->options(CompensationCycle::BASES)->required()->live(),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    Select::make('compensation_budget_id')->label('Budget (optional)')->native(false)
                        ->options(fn () => CompensationBudget::query()->where('status', 'approved')->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText('Approved budgets only; approval of the plan is refused when it would overspend.'),
                    Select::make('performance_cycle_id')->label('Performance cycle (leave empty for an ad hoc plan)')->native(false)
                        ->options(fn () => PerformanceCycle::query()->orderByDesc('period_end')->pluck('name', 'id')->all())
                        ->helperText('Only finalized ratings are read; they only prefill lines that people still review and approve.'),
                    TextInput::make('default_increase_percent')->label('Increase for everyone when no grid is used (%)')->numeric()->minValue(-100)->maxValue(1000)->default(0)
                        ->visible(fn (Get $get) => ! $amount($get)),
                    TextInput::make('default_increase_amount')->label('Increase for everyone when no grid is used (annual amount)')->numeric()
                        ->visible($amount),
                ]),
                Toggle::make('prorate_on_tenure')->label('Do you want to prorate the increment as per tenure?')->live()
                    ->helperText('The increment × full months of service ÷ the months for a full increment (at most the full increment).'),
                Grid::make(['default' => 1, 'md' => 3])->visible($prorate)->schema([
                    Radio::make('proration_basis')->label('Count months')->options(CompensationCycle::PRORATION_BASES)->required($prorate),
                    DatePicker::make('proration_cutoff')->label('Up to (cut-off date)')->native(false)->helperText('Empty = the effective date.'),
                    TextInput::make('proration_full_months')->label('Months for a full increment')->numeric()->integer()->minValue(1)->maxValue(120)->required($prorate),
                ]),
            ]),
            Step::make('Grid')->description('Annual CTC bands × ratings')->schema([
                Hidden::make('legacy_matrix'),
                Text::make(fn (Get $get) => 'Each band of annual CTC gives a value'.($get('increment_basis') === 'amount' ? ' (annual amount)' : ' (%)').' — by finalized rating (rows, when the plan reads a performance cycle) or for any other rating. Bands run upwards without overlapping; leave "To" empty on the last band for no upper limit. Without any band, the value of the previous step applies to everyone. Employees whose CTC is in no band are skipped (error file).'),
                Text::make(fn (Get $get) => filled($get('legacy_matrix')) && empty($get('bands')) ? new HtmlString('<span class="text-sm text-warning-600">The plan being cloned uses a rating-label matrix ('.e(collect((array) $get('legacy_matrix'))->map(fn ($v, $k) => "{$k}: {$v}")->implode(', ')).'); it is kept unless you add bands.</span>') : '')
                    ->visible(fn (Get $get) => filled($get('legacy_matrix'))),
                Repeater::make('bands')->label('Annual CTC bands')->addActionLabel('+ Add another band')->defaultItems(0)->reorderable(false)->collapsible()
                    ->itemLabel(fn (array $state) => filled($state['ctc_from'] ?? null) ? number_format((float) $state['ctc_from'], 0).(filled($state['ctc_to'] ?? null) ? ' – '.number_format((float) $state['ctc_to'], 0) : ' and above') : 'New band')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])->schema([
                            TextInput::make('ctc_from')->label('CTC from')->numeric()->minValue(0)->required(),
                            TextInput::make('ctc_to')->label('CTC to')->numeric()->minValue(0),
                            TextInput::make('value')->label('Value for any other rating')->numeric(),
                        ]),
                        Repeater::make('ratings')->label('By rating')->addActionLabel('+ Add another rating')->defaultItems(0)->reorderable(false)
                            ->schema([
                                Grid::make(3)->schema([
                                    TextInput::make('from')->label('Rating from')->numeric()->required(),
                                    TextInput::make('to')->label('Rating to')->numeric()->required(),
                                    TextInput::make('value')->label('Value')->numeric()->required(),
                                ]),
                            ]),
                    ]),
            ]),
            Step::make('Review')->description('Summary before the plan is created')->schema([
                Text::make(fn (Get $get) => self::reviewHtml(self::stateFrom($get))),
                Toggle::make('populate_now')->label('Calculate the plan now (employees and their proposed CTC)')->default(true),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private static function stateFrom(Get $get): array
    {
        $keys = ['code', 'name', 'cycle_type', 'company_id', 'effective_from', 'organisation', 'codes', 'exclude_codes', 'increment_basis', 'compensation_budget_id', 'performance_cycle_id',
            'default_increase_percent', 'default_increase_amount', 'prorate_on_tenure', 'proration_basis', 'proration_cutoff', 'proration_full_months', 'bands', 'legacy_matrix'];

        return collect($keys)->mapWithKeys(fn (string $k) => [$k => $get($k)])->all();
    }

    /**
     * The wizard's state as the service takes it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function serviceData(array $data): array
    {
        $bands = array_values(array_map(fn ($band) => [
            'ctc_from' => $band['ctc_from'] ?? null, 'ctc_to' => $band['ctc_to'] ?? null, 'value' => $band['value'] ?? null,
            'ratings' => array_values(array_map(fn ($r) => ['from' => $r['from'] ?? null, 'to' => $r['to'] ?? null, 'value' => $r['value'] ?? null], (array) ($band['ratings'] ?? []))),
        ], (array) ($data['bands'] ?? [])));
        $amount = ($data['increment_basis'] ?? 'ctc_percent') === 'amount';

        return [
            'code' => $data['code'] ?? null, 'name' => $data['name'] ?? null, 'cycle_type' => $data['cycle_type'] ?? null, 'company_id' => $data['company_id'] ?? null,
            'effective_from' => $data['effective_from'] ?? null,
            'compensation_budget_id' => filled($data['compensation_budget_id'] ?? null) ? (int) $data['compensation_budget_id'] : null,
            'performance_cycle_id' => filled($data['performance_cycle_id'] ?? null) ? (int) $data['performance_cycle_id'] : null,
            'increment_basis' => $data['increment_basis'] ?? 'ctc_percent',
            'default_increase_percent' => $amount ? 0 : ($data['default_increase_percent'] ?? 0),
            'default_increase_amount' => $amount ? ($data['default_increase_amount'] ?? null) : null,
            'prorate_on_tenure' => (bool) ($data['prorate_on_tenure'] ?? false), 'proration_basis' => $data['proration_basis'] ?? null,
            'proration_cutoff' => $data['proration_cutoff'] ?? null, 'proration_full_months' => $data['proration_full_months'] ?? null,
            'rating_increase_percent' => $bands !== [] ? ['bands' => $bands] : (filled($data['legacy_matrix'] ?? null) ? (array) $data['legacy_matrix'] : null),
            'population' => ['organisation' => (array) ($data['organisation'] ?? []), 'codes' => $data['codes'] ?? null, 'exclude_codes' => $data['exclude_codes'] ?? null],
        ];
    }

    /** @var array<string, HtmlString> the Review step per form state, once per request (the wizard renders every step) */
    private static array $reviews = [];

    /** The Review step: the calculation run without writing (headcount, cost, increase, budget, skipped). */
    public static function reviewHtml(array $state): HtmlString
    {
        $key = md5((string) json_encode($state).'|'.auth()->id());

        return self::$reviews[$key] ??= self::review($state);
    }

    private static function review(array $state): HtmlString
    {
        $user = auth()->user();
        try {
            $service = app(CompensationCycles::class);
            $cycle = $service->draftOf(self::serviceData($state), $user);
            $plan = $service->plan($cycle, $user);
        } catch (RuntimeException $e) {
            return new HtmlString('<p class="text-sm text-danger-600" data-increment-review-error>'.e($e->getMessage()).'</p>');
        } catch (Throwable) {
            return new HtmlString('<p class="text-sm text-gray-500">Fill in the earlier steps to see the summary.</p>');
        }
        $rows = array_map(fn (array $l) => ['currency' => $l['current']->currency, 'proposed' => $l['result']['proposed'], 'current' => $l['current']->ctcAnnual, 'percent' => $l['result']['percent']], $plan['lines']);
        $summary = app(IncrementCalculator::class)->summarise($cycle, $rows, count($plan['errors']));
        $skipped = array_slice($plan['errors'], 0, 10);

        return new HtmlString(self::summaryHtml($summary)
            .($skipped === [] ? '' : '<div class="mt-2 text-sm"><p class="font-semibold text-warning-600">Warning: '.count($plan['errors']).' employee(s) would be skipped</p><ul class="list-disc pl-5">'
                .collect($skipped)->map(fn ($e) => '<li>'.e(($e['employee_code'] ?? '—').': '.$e['reason']).'</li>')->implode('').'</ul>'
                .(count($plan['errors']) > 10 ? '<p class="text-gray-500">The error file lists all of them once the plan is calculated.</p>' : '').'</div>')
            .'<p class="mt-2 text-xs text-gray-500">Compensation rules (structure, lifecycle) are checked again for each line when the plan is calculated; a line they refuse goes to the error file.</p>');
    }

    /** The summary tiles (HROne's "Average increment", "Total Budget", "Total Increment"). */
    public static function summaryHtml(array $s): string
    {
        $money = fn (?float $v) => $v === null ? '—' : e($s['currency']).' '.number_format($v, 2);
        $pct = fn (?float $v) => $v === null ? '—' : number_format($v, 2).'%';
        $tiles = [
            'Employees' => (string) $s['headcount'],
            'Current cost (annual CTC)' => $money($s['current_cost']),
            'Proposed cost' => $money($s['proposed_cost']),
            'Total increment' => $money($s['increase']).' ('.$pct($s['increase_percent']).')',
            'Average increment' => $pct($s['average_percent']),
            'Total budget' => $s['budget'] === null ? 'No budget' : $money($s['budget']),
            'Budget used' => $s['budget'] === null ? '—' : $pct($s['budget_used_percent']).' · left after the plan '.$money($s['budget_left_after']),
            'Skipped' => (string) $s['skipped'],
        ];
        $html = '<dl class="grid grid-cols-2 gap-3 text-sm md:grid-cols-4" data-increment-summary>'
            .collect($tiles)->map(fn ($v, $k) => '<div class="rounded-lg p-3 ring-1 ring-gray-950/5 dark:ring-white/10"><dt class="text-xs text-gray-500 dark:text-gray-400">'.e($k).'</dt><dd class="font-semibold tabular-nums">'.$v.'</dd></div>')->implode('')
            .'</dl>';
        if ($s['over_budget']) {
            $html .= '<p class="mt-2 text-sm text-danger-600">The plan would spend more than is left in its budget; approval would be refused.</p>';
        }
        if ($s['other_currencies'] !== []) {
            $html .= '<p class="mt-2 text-xs text-gray-500">Amounts are in '.e($s['currency']).'; also in the plan: '.e(collect($s['other_currencies'])->map(fn ($n, $c) => "{$c} ({$n})")->implode(', ')).'.</p>';
        }
        if ($s['unknown_base'] > 0) {
            $html .= '<p class="mt-2 text-xs text-gray-500">'.$s['unknown_base'].' line(s) calculated before the increment calculator have no recorded starting CTC and are left out of the totals; calculate the plan again to include them.</p>';
        }

        return $html;
    }
}
