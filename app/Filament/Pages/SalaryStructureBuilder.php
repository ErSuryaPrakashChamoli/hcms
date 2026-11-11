<?php

namespace App\Filament\Pages;

use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\StructureBuilder;
use App\Domain\Compensation\Support\StructureRules;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Filament\Resources\SalaryStructures\SalaryStructureResource;
use App\Filament\Support\SalaryFormulaUi;
use App\Filament\Support\StructureApplicabilitySchema;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * The salary structure builder: a structure set up in five plain steps — name and company, how pay is set,
 * the components (picked from a list or written in plain English), a check on a sample salary, and save /
 * send for approval. StructureBuilder turns the rules into the formulas payroll runs and saves them through
 * the versioned approval workflow (docs/architecture/salary-structure-builder.md).
 */
class SalaryStructureBuilder extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    public const STEPS = [
        ['key' => 'basics', 'label' => 'Name and company', 'help' => 'What the structure is called, who it is for, and when it starts.'],
        ['key' => 'basis', 'label' => 'How pay is set', 'help' => 'Whether each employee gets a CTC to split, or an amount for each component.'],
        ['key' => 'components', 'label' => 'Components', 'help' => 'What the salary is made of, and how each part is worked out.'],
        ['key' => 'check', 'label' => 'Check', 'help' => 'Try it on a sample salary. Nothing is saved here.'],
        ['key' => 'save', 'label' => 'Save', 'help' => 'Read it through, then save it or send it for approval.'],
    ];

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'salary-structure-builder';

    protected string $view = 'filament.pages.salary-structure-builder';

    #[Url(as: 'structure')]
    public ?int $structureId = null;

    /** The step shown, and the furthest step reached (earlier steps can be reopened from the step list). */
    public int $step = 0;

    public int $reached = 0;

    /** @var array<string, mixed> */
    public array $data = [];

    /** What stops the current step, in plain words. @var list<string> */
    public array $problems = [];

    /** Line-by-line result of the last plain-English conversion. @var list<array{line: string, status: string, text: string}> */
    public array $report = [];

    /** The plain-English text the rows were last made from (null = rows changed since, or never converted). */
    public ?string $convertedText = null;

    /** The starting point the rows were filled from (a new structure only). */
    public ?string $appliedStart = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('compensation.configure') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $builder = app(StructureBuilder::class);
        $structure = $this->structure();
        abort_if($this->structureId !== null && $structure === null, 404);
        $state = $structure ? $builder->load($structure) : [
            'name' => '', 'company_id' => null, 'narrow_by' => [], 'grade_ids' => [], 'applicability' => [], 'effective_from' => $builder->defaultStart(),
            'currency' => null, 'ctc_mode' => 'ctc', 'start' => 'ready', 'copy_from' => null, 'rules' => [],
        ];
        $this->form->fill($state + ['input_mode' => 'list', 'english' => '', 'sample_ctc' => 600000, 'sample_pf' => true, 'samples' => []]);
        $this->reached = $structure ? count(self::STEPS) - 1 : 0;
    }

    public function getTitle(): string
    {
        return $this->structure() ? 'Change '.$this->structure()->name : 'New salary structure';
    }

    public function getSubheading(): ?string
    {
        return $this->locked() ? null : 'Five short steps. You can go back to any step you have done.';
    }

    protected function getHeaderActions(): array
    {
        return [
            SalaryFormulaUi::guideAction()->label('Guide'),
            Action::make('downloadGuide')->label('Download guide')->icon('heroicon-m-arrow-down-tray')->color('gray')
                ->action(fn () => response()->streamDownload(fn () => print (SalaryFormulaUi::guideDocument()), SalaryFormulaUi::GUIDE_FILE, ['Content-Type' => 'text/html'])),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Group::make($this->basicsStep())->columns(2)->visible(fn () => $this->step === 0),
            Group::make($this->basisStep())->visible(fn () => $this->step === 1),
            Group::make($this->componentsStep())->visible(fn () => $this->step === 2),
            Group::make($this->checkStep())->visible(fn () => $this->step === 3),
        ]);
    }

    public function next(): void
    {
        $this->problems = [];
        $this->form->validate();
        $builder = app(StructureBuilder::class);
        if ($this->step === 0 && ($problem = $builder->dateProblem($this->structure(), $this->data['effective_from'] ?? null))) {
            throw ValidationException::withMessages(['data.effective_from' => $problem]);
        }
        if ($this->step === 1 && $this->structure() === null) {
            $this->applyStart();
        }
        if ($this->step === 2) {
            if (($this->data['input_mode'] ?? 'list') === 'english' && trim((string) ($this->data['english'] ?? '')) !== $this->convertedText) {
                $this->convert();
            }
            if (($this->data['input_mode'] ?? 'list') === 'english' && collect($this->report)->contains('status', 'error')) {
                $this->problems = ['Some lines were not understood. Change them (see the notes under the text), or remove them.'];

                return;
            }
            $compiled = $builder->compile((array) ($this->data['rules'] ?? []), $this->mode(), auth()->user());
            if ($compiled['problems'] !== []) {
                $this->problems = $compiled['problems'];

                return;
            }
        }
        $this->step = min($this->step + 1, count(self::STEPS) - 1);
        $this->reached = max($this->reached, $this->step);
    }

    public function previous(): void
    {
        $this->problems = [];
        $this->step = max(0, $this->step - 1);
    }

    public function goTo(int $step): void
    {
        if ($step >= 0 && $step <= $this->reached && $step < count(self::STEPS)) {
            $this->problems = [];
            $this->step = $step;
        }
    }

    /** Plain English → the component rows (replacing them), with a report of what each line was read as. */
    public function convert(): void
    {
        $text = trim((string) ($this->data['english'] ?? ''));
        $result = app(StructureBuilder::class)->fromEnglish($text, $this->mode(), auth()->user());
        $this->report = $result['report'];
        $this->convertedText = $text;
        $this->fillRules($result['rows']);
        $this->problems = [];
    }

    public function saveDraft(): void
    {
        $this->persist(false);
    }

    public function sendForApproval(): void
    {
        $this->persist(true);
    }

    /** A structure with a version waiting for approval is shown but not changed until it is approved or sent back. */
    public function locked(): bool
    {
        $structure = $this->structure();

        return $structure !== null && app(StructureBuilder::class)->versionFor($structure)?->status === 'pending_approval';
    }

    public function isLast(): bool
    {
        return $this->step === count(self::STEPS) - 1;
    }

    /** @return array<string, mixed> the breakup for the sample on the Check step */
    public function breakup(): array
    {
        $amounts = [];
        foreach ((array) ($this->data['samples'] ?? []) as $rowKey => $amount) {
            $key = (string) ($this->data['rules'][$rowKey]['component'] ?? '');
            if ($key !== '' && is_numeric($amount)) {
                $amounts[$key] = (float) $amount;
            }
        }

        return app(StructureBuilder::class)->preview((array) ($this->data['rules'] ?? []), $this->mode(), [
            'ctc' => $this->data['sample_ctc'] ?? 0, 'pf' => (bool) ($this->data['sample_pf'] ?? true), 'amounts' => $amounts, 'on' => $this->data['effective_from'] ?? null,
        ], auth()->user());
    }

    /**
     * What the Save step reads back: the basics, each component as a sentence (in calculation order), the
     * components that will be added to the component list, and what happens after saving.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $builder = app(StructureBuilder::class);
        $compiled = $builder->compile((array) ($this->data['rules'] ?? []), $this->mode(), auth()->user());
        $names = $compiled['names'];
        $order = array_map(fn (array $line) => $line['key'], $compiled['lines']);
        $rows = $order === [] ? $compiled['rows'] : array_replace(array_flip($order), $compiled['rows']);
        $date = filled($this->data['effective_from'] ?? null) ? Carbon::parse($this->data['effective_from'])->format('j M Y') : '—';

        return [
            'problems' => $compiled['problems'],
            'basics' => [
                ['Name', (string) ($this->data['name'] ?? '')],
                ['For', StructureApplicabilitySchema::describeState($this->data)],
                ['Starts', $date],
                ['Pay is set', $this->mode() === 'components' ? 'From component amounts (the CTC is added up)' : 'From a CTC that is split into components'],
            ],
            'sentences' => collect($rows)->map(fn (array $row, string $key) => [
                'text' => StructureRules::sentence($row, $names, str_starts_with($key, 'new:') && ($row['new_type'] ?? 'earning') !== 'earning' ? $row['new_type'] : null),
                'new' => str_starts_with($key, 'new:'),
            ])->values()->all(),
            'added' => collect($compiled['components'])->filter(fn (SalaryComponent $c) => ! $c->exists)->map(fn (SalaryComponent $c) => $c->name.' ('.config('peopleos.payroll.component_types.'.$c->type, $c->type).')')->values()->all(),
            'date' => $date,
            'existing' => $this->structure() !== null,
        ];
    }

    public function structure(): ?SalaryStructure
    {
        return $this->structureId === null ? null : SalaryStructure::query()->find($this->structureId);
    }

    public function stepLabel(int $step): ?string
    {
        return self::STEPS[$step]['label'] ?? null;
    }

    private function persist(bool $submit): void
    {
        if ($this->locked()) {
            return;
        }
        try {
            $version = app(StructureBuilder::class)->save($this->data, $this->structure(), auth()->user(), $submit);
        } catch (CompensationRuleViolation $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();

            return;
        }
        $name = $version->structure?->name ?? (string) ($this->data['name'] ?? '');
        Notification::make()->success()
            ->title($submit ? "{$name} sent for approval" : "{$name} saved as a draft")
            ->body($submit ? 'Someone with approval rights (not you) approves it. Then you can give it to employees.' : 'Send it for approval when it is ready; nothing changes for anyone until it is approved.')
            ->send();
        $this->redirect(SalaryStructureResource::getUrl('index'));
    }

    private function mode(): string
    {
        return ($this->data['ctc_mode'] ?? 'ctc') === 'components' ? 'components' : 'ctc';
    }

    /** A new structure's starting point (ready-made, a copy, or empty), applied when it changes. */
    private function applyStart(): void
    {
        $start = (string) ($this->data['start'] ?? 'ready');
        $signature = $start.':'.$this->mode().':'.($start === 'copy' ? (string) ($this->data['copy_from'] ?? '') : '');
        if ($signature === $this->appliedStart) {
            return;
        }
        $builder = app(StructureBuilder::class);
        $rows = match ($start) {
            'copy' => ($source = SalaryStructure::query()->find((int) ($this->data['copy_from'] ?? 0))) ? $builder->load($source)['rules'] : [],
            'blank' => [],
            default => $builder->template($this->mode()),
        };
        $this->appliedStart = $signature;
        $this->report = [];
        $this->convertedText = null;
        $this->fillRules($rows);
        $this->data['english'] = $builder->toEnglish($rows);
    }

    private function fillRules(array $rows): void
    {
        $this->form->fill(['rules' => $rows] + $this->data);
    }

    /** @return array<int, Component> */
    private function basicsStep(): array
    {
        return [
            TextInput::make('name')->label('Structure name')->required()->maxLength(255)->placeholder('e.g. Staff – Mumbai')->columnSpanFull(),
            ...StructureApplicabilitySchema::fields(),
            DatePicker::make('effective_from')->label('Starts from')->native(false)->required()->displayFormat('j M Y')
                ->helperText('Payroll uses it for pay from this date.'),
        ];
    }

    /** @return array<int, Component> */
    private function basisStep(): array
    {
        $new = $this->structureId === null;

        return [
            Radio::make('ctc_mode')->label('How do you fix an employee’s salary?')->required()->live()
                ->options(['ctc' => 'I give a yearly CTC, and this structure splits it', 'components' => 'I give an amount for each component, and the CTC is added up'])
                ->descriptions(['ctc' => 'Most companies do this. For example, CTC 6,00,000 → Basic, HRA and Special allowance.', 'components' => 'For example, Basic 20,000 and Special allowance 8,000 a month, typed in for each employee.']),
            Radio::make('start')->label('Start with')->required()->live()->visible($new)
                ->options(['ready' => 'A ready-made structure', 'copy' => 'A copy of another structure', 'blank' => 'An empty list'])
                ->descriptions(fn (Get $get) => [
                    'ready' => $get('ctc_mode') === 'components' ? 'Basic and Special allowance per employee, HRA 40% of Basic. Change anything next.' : 'Basic 50% of CTC, HRA 40% of Basic, and Special allowance takes the rest. Change anything next.',
                    'copy' => 'Begin from a structure you already have, e.g. for another company.',
                    'blank' => 'Add every component yourself.',
                ]),
            Select::make('copy_from')->label('Structure to copy')->required()->searchable()->visible(fn (Get $get) => $new && $get('start') === 'copy')
                ->options(fn () => SalaryStructure::query()->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    /** @return array<int, Component> */
    private function componentsStep(): array
    {
        $english = fn (Get $get) => $get('input_mode') === 'english';

        return [
            ToggleButtons::make('input_mode')->hiddenLabel()->inline()->live()->grouped()
                ->options(['list' => 'Pick from a list', 'english' => 'Write in plain English'])
                ->icons(['list' => 'heroicon-m-table-cells', 'english' => 'heroicon-m-pencil'])
                ->afterStateUpdated(function (?string $state) {
                    if ($state === 'english') {
                        $this->data['english'] = app(StructureBuilder::class)->toEnglish((array) ($this->data['rules'] ?? []));
                        $this->convertedText = trim((string) $this->data['english']);
                        $this->report = [];
                    }
                }),
            Repeater::make('rules')->hiddenLabel()->visible(fn (Get $get) => ! $english($get))
                ->extraAttributes(['class' => 'pos-rules'])
                ->table([
                    TableColumn::make('Component')->width('24%'),
                    TableColumn::make('Worked out as')->width('20%'),
                    TableColumn::make('Value')->width('38%'),
                    TableColumn::make('Pay')->width('18%'),
                ])
                ->schema($this->ruleFields())
                // No drag handle: the builder puts components in calculation order itself.
                ->defaultItems(0)->reorderable(false)->live()
                ->addAction(fn (Action $action) => $action->label('Add a component')->icon('heroicon-m-plus'))
                ->deleteAction(fn (Action $action) => $action->icon('heroicon-m-x-mark')->color('gray')->tooltip('Remove'))
                ->afterStateUpdated(fn () => $this->convertedText = null),
            Textarea::make('english')->hiddenLabel()->rows(9)->visible($english)
                ->placeholder("Basic is 50% of CTC\nHRA is 40% of Basic\nConveyance is a fixed 1,600 a month\nPerformance bonus is 10% of CTC, variable\nSpecial allowance is whatever is left of the CTC")
                ->helperText('One component per line. Say how each is worked out: “% of CTC”, “% of Basic”, “a fixed 1,600 a month”, “15,000 a year”, “entered for each employee”, or “whatever is left of the CTC”. Add “at most 20,000” for a cap and “variable” for variable pay. PF, ESI, PT and TDS are added by payroll.'),
        ];
    }

    /**
     * One table row: the component (with a "New · Earning" chip for one the rules add), how it is worked out, its value
     * ("50 % of CTC", "40 % of Basic", "1,600 a month", or a chip saying where the amount comes from) with an optional
     * min / max behind a link, and Fixed / Variable. Labels show only on narrow screens, where rows become cards.
     *
     * @return array<int, Component>
     */
    private function ruleFields(): array
    {
        $builder = app(StructureBuilder::class);
        $how = fn (Get $get) => (string) $get('how');
        $percent = fn (Get $get) => in_array($how($get), StructureRules::PERCENT, true);
        $canAdd = auth()->user()?->can('payroll.manage') ?? false;
        $component = fn (Get $get) => ($key = (string) $get('component')) !== '' && ! str_starts_with($key, 'new:') ? SalaryComponent::query()->find((int) $key) : null;
        $limited = fn (Get $get) => (bool) $get('limits') || filled($get('min')) || filled($get('max'));

        return [
            Group::make([
                Select::make('component')->label('Component')->required()->searchable()->preload()->live()->placeholder('Pick or type a name')->selectablePlaceholder(false)
                    ->options(fn () => $builder->componentOptions() + collect((array) ($this->data['rules'] ?? []))->pluck('component')
                        ->filter(fn ($key) => is_string($key) && str_starts_with($key, 'new:'))->mapWithKeys(fn (string $key) => [$key => substr($key, 4)])->all())
                    ->getSearchResultsUsing(function (string $search) use ($builder, $canAdd) {
                        $search = trim($search);
                        $found = collect($builder->componentOptions())->filter(fn (string $label) => Str::contains(Str::lower($label), Str::lower($search)))->all();
                        if ($canAdd && StructureBuilder::isName($search) && $builder->match($search) === null) {
                            $found['new:'.Str::ucfirst(Str::limit($search, 60, ''))] = '+ Add “'.Str::limit($search, 60, '').'” as a new component';
                        }

                        return $found;
                    })
                    ->getOptionLabelUsing(fn ($value) => is_string($value) && str_starts_with($value, 'new:') ? substr($value, 4) : ($builder->componentOptions()[(string) $value] ?? null))
                    ->afterStateUpdated(function ($state, Set $set) {
                        if (($c = SalaryComponent::query()->find((int) $state)) && $c->isInput()) {
                            $set('how', 'input');
                        }
                    }),
                Select::make('new_type')->label('New component')->default('earning')->selectablePlaceholder(false)->live()
                    ->options(['earning' => 'New · earning', 'deduction' => 'New · deduction', 'employer_contribution' => 'New · employer cost', 'reimbursement' => 'New · reimbursement'])
                    ->extraAttributes(['class' => 'pos-rule-chip'])
                    ->visible(fn (Get $get) => str_starts_with((string) $get('component'), 'new:')),
            ])->dense()->extraAttributes(fn (Get $get) => ['class' => 'pos-rule-name', 'data-pos-type' => str_starts_with((string) $get('component'), 'new:') ? ((string) $get('new_type') ?: 'earning') : ($component($get)?->type ?? '')]),
            Select::make('how')->label('Worked out as')->required()->live()->selectablePlaceholder(false)->default(fn () => $this->mode() === 'components' ? 'per_employee' : 'percent_ctc')
                ->options(fn (Get $get) => $component($get)?->isInput() ? ['input' => 'Formula input'] : array_diff_key([
                    'percent_ctc' => '% of CTC', 'percent_of' => '% of another', 'fixed' => 'Fixed amount', 'per_employee' => 'Per employee',
                    'balance' => 'Rest of CTC', 'formula' => 'Formula',
                ], $this->mode() === 'components' ? array_flip(StructureRules::NEEDS_CTC) : [])),
            Group::make([
                Grid::make(2)->dense()->schema([
                    TextInput::make('value')->label('Value')->numeric()->minValue(0)->live(onBlur: true)
                        ->suffix(fn (Get $get) => match ($how($get)) {
                            'percent_ctc' => '% of CTC', 'percent_of' => '%', default => 'a month'
                        })
                        ->placeholder(fn (Get $get) => $percent($get) ? '50' : '1600')
                        ->columnSpan(fn (Get $get) => $how($get) === 'percent_of' ? 1 : 2)
                        ->visible(fn (Get $get) => in_array($how($get), [...StructureRules::PERCENT, 'fixed'], true)),
                    Select::make('of')->label('Of')->placeholder('of …')->live()
                        ->options(function (Get $get) use ($builder) {
                            $rows = (array) ($this->data['rules'] ?? []);
                            $listed = collect($rows)->pluck('component')->filter()->map(fn ($key) => (string) $key)->flip();

                            return collect($builder->names($rows))->intersectByKeys($listed)->except((string) $get('component'))->map(fn (string $name) => "of {$name}")->all();
                        })
                        ->visible(fn (Get $get) => $how($get) === 'percent_of'),
                    TextInput::make('min')->label('Min a month')->numeric()->minValue(0)->prefix('Min')->placeholder('none')
                        ->extraAttributes(['class' => 'pos-rule-limit'])->visible(fn (Get $get) => $percent($get) && $limited($get)),
                    TextInput::make('max')->label('Max a month')->numeric()->minValue(0)->prefix('Max')->placeholder('none')
                        ->extraAttributes(['class' => 'pos-rule-limit'])->visible(fn (Get $get) => $percent($get) && $limited($get)),
                ]),
                Actions::make([
                    Action::make('limit')->label('Add a min / max')->link()->size('sm')->icon('heroicon-m-plus-small')->color('gray')
                        ->action(fn (Set $set) => $set('limits', true)),
                ])->visible(fn (Get $get) => $percent($get) && ! $limited($get)),
                TextInput::make('formula')->label('Formula')->placeholder('e.g. basic * 12 / 100')->live(onBlur: true)
                    ->extraAttributes(['class' => 'pos-rule-formula'])->visible(fn (Get $get) => $how($get) === 'formula'),
                Text::make(fn (Get $get) => match ($how($get)) {
                    'per_employee' => 'Typed in on each employee’s salary',
                    'balance' => 'Balances to the CTC',
                    'input' => 'Typed in; used only in formulas',
                    default => '',
                })->badge()->color(fn (Get $get) => $how($get) === 'balance' ? 'info' : 'gray')
                    ->icon(fn (Get $get) => $how($get) === 'balance' ? 'heroicon-m-scale' : 'heroicon-m-pencil-square')
                    ->visible(fn (Get $get) => in_array($how($get), ['per_employee', 'balance', 'input'], true)),
                Hidden::make('limits'),
            ])->dense()->extraAttributes(['class' => 'pos-rule-value']),
            ToggleButtons::make('nature')->label('Pay')->grouped()->default('fixed')
                ->options(['fixed' => 'Fixed', 'variable' => 'Variable'])->colors(['fixed' => 'gray', 'variable' => 'warning'])
                ->extraAttributes(['class' => 'pos-rule-pay']),
            Hidden::make('frequency')->default('monthly'),
        ];
    }

    /** @return array<int, Component> */
    private function checkStep(): array
    {
        $perEmployee = fn () => collect((array) ($this->data['rules'] ?? []))->filter(fn ($row) => is_array($row) && ($row['how'] ?? null) === 'per_employee' && filled($row['component'] ?? null));
        $names = fn () => app(StructureBuilder::class)->names((array) ($this->data['rules'] ?? []));

        return [
            Grid::make(['default' => 1, 'md' => 3])->schema([
                TextInput::make('sample_ctc')->label('Yearly CTC to try')->numeric()->minValue(0)->live(debounce: 500)->visible(fn () => $this->mode() === 'ctc'),
                Toggle::make('sample_pf')->label('Covered by PF')->live()->inline(false),
            ]),
            Grid::make(['default' => 1, 'md' => 3])->schema(fn () => $perEmployee()->map(fn (array $row, string $rowKey) => TextInput::make("samples.{$rowKey}")
                ->label(($names()[$row['component']] ?? 'Amount').' a month')->numeric()->minValue(0)->live(debounce: 500)->placeholder('Sample amount'))->values()->all())
                ->visible(fn () => $perEmployee()->isNotEmpty()),
        ];
    }
}
