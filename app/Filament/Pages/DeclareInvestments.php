<?php

namespace App\Filament\Pages;

use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\InvestmentDeclarations;
use App\Domain\Compliance\Support\InvestmentCatalogue;
use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use NumberFormatter;
use RuntimeException;
use UnitEnum;

/**
 * Guided investment declaration (§32 TDS) for someone filing for the first time: choose the regime, then one step per
 * section listing every option that counts there (what it is, what proof is needed), tick what applies and enter the
 * amount; the last step totals each section against what payroll counts. InvestmentDeclarations saves the declaration
 * and one proof row per item. Employees file their own; payroll.manage files for anyone in scope (?employee=&year=).
 */
class DeclareInvestments extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Declare investments';

    protected static ?string $title = 'Declare your investments';

    protected static ?int $navigationSort = 34;

    protected static ?string $slug = 'declare-investments';

    protected string $view = 'filament.pages.declare-investments';

    /** @var array<string, mixed> */
    public array $data = [];

    private const MAX_AMOUNT = 1_000_000_000;

    private const STEP_ICONS = ['HRA_RENT' => Heroicon::OutlinedHome, '80C' => Heroicon::OutlinedBanknotes, '80CCD1B' => Heroicon::OutlinedBuildingLibrary, '80D' => Heroicon::OutlinedHeart, '80E' => Heroicon::OutlinedAcademicCap, '80G' => Heroicon::OutlinedGift, '80TTA' => Heroicon::OutlinedCurrencyRupee];

    private const REGIMES = [
        'new' => 'Lower tax rates and a bigger standard deduction (₹75,000), but almost none of the deductions in the next steps count. Good if you have few investments, no rent or little insurance to declare.',
        'old' => 'Higher tax rates, but rent (HRA), 80C savings, health insurance, NPS and the other items in the next steps reduce your taxable income. Good if you have many of them.',
    ];

    /** Per-request memos (Livewire does not keep private properties between requests). */
    private ?Employee $employeeMemo = null;

    private ?EmployeeTaxDeclaration $declarationMemo = null;

    /** @var Collection<string, TdsEmployeeInvestment>|null */
    private ?Collection $itemsMemo = null;

    private ?string $memoFor = null;

    private ?float $pfMemo = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TenantContext::class)->has()
            && ($user->hasPermission('payroll.manage') || $user->hasPermission('payroll.payslip'))
            && app(InvestmentDeclarations::class)->employeesFor($user)->exists();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $employees = $this->declarations()->employeesFor(auth()->user());
        $requested = request()->integer('employee');
        $employeeId = $requested > 0 && (clone $employees)->whereKey($requested)->exists() ? $requested : (clone $employees)->where('user_id', auth()->id())->value('id');
        $year = request()->query('year');

        $this->fillFor($employeeId, is_string($year) && array_key_exists($year, $this->declarations()->years()) ? $year : app(FinancialYear::class)->label(now()));
    }

    public function getSubheading(): ?string
    {
        return 'Tell payroll what you invest in and pay for, so the right amount of income tax is deducted from your salary each month.';
    }

    public function form(Schema $schema): Schema
    {
        // Read-only (verified) disables the steps, not the wizard, so its Next and Back keep working.
        $steps = array_map(fn (Step $step) => $step->disabled(fn () => ! $this->editable()), [$this->startStep(), ...$this->sectionSteps(), $this->previousEmployerStep(), $this->reviewStep()]);

        return $schema->statePath('data')->components([
            Section::make()->columns(3)->schema([
                Select::make('employee_id')->label('Employee')->searchable()->required()
                    ->visible(fn () => auth()->user()->hasPermission('payroll.manage'))
                    ->getSearchResultsUsing(fn (string $search) => $this->declarations()->employeesFor(auth()->user())->with('person')
                        ->where(fn (Builder $query) => $query->where('employee_code', 'like', "%{$search}%")->orWhereHas('person', fn (Builder $person) => $person->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")))
                        ->limit(25)->get()->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->employee_code} · {$e->person?->display_name}"])->all())
                    ->getOptionLabelUsing(fn ($value) => ($e = Employee::query()->with('person')->find($value)) ? "{$e->employee_code} · {$e->person?->display_name}" : null)
                    ->live()->afterStateUpdated(fn ($state, Get $get) => $this->fillFor($state ? (int) $state : null, (string) $get('financial_year'))),
                Select::make('financial_year')->label('Financial year')->options(fn () => $this->declarations()->years())->required()->selectablePlaceholder(false)
                    ->helperText('April to March')
                    ->live()->afterStateUpdated(fn ($state, Get $get) => $this->fillFor($get('employee_id') ? (int) $get('employee_id') : null, (string) $state)),
                Text::make(fn () => $this->statusLine())->columnSpan(fn () => auth()->user()->hasPermission('payroll.manage') ? 1 : 2),
            ]),
            Callout::make('Payroll has verified this declaration')->success()
                ->description('It can no longer be changed here. If something needs to change, contact your payroll team.')
                ->visible(fn () => $this->employee() !== null && ! $this->editable()),
            Wizard::make($steps)->skippable()
                ->visible(fn () => $this->employee() !== null)
                ->submitAction(view('filament.pages.declare-investments.submit', ['page' => $this])), // rendered late: knows the current selection
        ]);
    }

    private function startStep(): Step
    {
        return Step::make('Start')->icon(Heroicon::OutlinedScale)->description('Tax regime')->schema([
            Callout::make('How this works')->info()
                ->description('Go through the steps one by one. In each step, tick everything that applies to you this financial year and enter the amount; leave a step empty if nothing applies. Save as a draft any time and come back later. Payroll uses your declaration to work out the income tax deducted from your salary every month, and will ask for proofs (receipts, statements) later in the year.'),
            Radio::make('regime')->label('Which tax regime do you choose?')->required()->live()
                ->options(config('peopleos.compliance.tax_regimes'))->descriptions(self::REGIMES),
            Text::make('Not sure? Fill in the steps anyway: the last step shows how much each section would reduce your taxable income under the old regime, which helps you compare. You can change your choice until payroll verifies your declaration.'),
        ]);
    }

    /** @return list<Step> */
    private function sectionSteps(): array
    {
        return collect(InvestmentCatalogue::SECTIONS)->map(fn (array $meta, string $section) => $section === 'HRA_RENT' ? $this->rentStep($meta) : $this->sectionStep($section, $meta))->values()->all();
    }

    /** @param  array<string, mixed>  $meta */
    private function rentStep(array $meta): Step
    {
        $pays = fn (Get $get) => (bool) $get('rent.pays');

        return Step::make($meta['step'])->icon(self::STEP_ICONS['HRA_RENT'])->description($meta['code'])->schema([
            $this->regimeCallout('HRA_RENT'),
            Text::make($meta['intro']),
            Toggle::make('rent.pays')->label('I pay rent for the home I live in')->live()
                ->disabled(fn () => $this->isLocked('HRA_RENT.rent'))
                ->helperText(fn () => $this->itemNote('HRA_RENT.rent')),
            Section::make('Your rent')->compact()->columns(2)->visible($pays)->disabled(fn () => $this->isLocked('HRA_RENT.rent'))->schema([
                TextInput::make('rent.monthly')->label('Rent per month')->prefix('₹')->numeric()->minValue(1)->maxValue(self::MAX_AMOUNT)->required()->live(onBlur: true),
                TextInput::make('rent.months')->label('Months you rent in this financial year')->numeric()->integer()->minValue(1)->maxValue(12)->required()->live(onBlur: true)
                    ->helperText('12 if you rent for the whole year (April to March)'),
                Toggle::make('rent.metro')->label('The home is in Delhi, Mumbai, Kolkata or Chennai')
                    ->helperText('A bigger part of HRA is tax-free in these four cities')->columnSpanFull(),
                TextInput::make('rent.landlord_name')->label('Landlord’s name')->maxLength(120),
                TextInput::make('rent.landlord_pan')->label('Landlord’s PAN')->maxLength(10)
                    ->rule('regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/')->validationMessages(['regex' => 'A PAN looks like ABCDE1234F.'])
                    ->helperText('Needed when your rent is more than ₹1,00,000 a year'),
                TextInput::make('rent.address')->label('Address of the rented home')->maxLength(255)->columnSpanFull(),
                Text::make(fn (Get $get) => 'Rent for the year: '.self::inr($this->rentAnnual($get)))->columnSpanFull(),
            ]),
            Text::make('Proof needed later: '.$meta['items']['rent']['proof'])->visible($pays),
        ]);
    }

    /** @param  array<string, mixed>  $meta */
    private function sectionStep(string $section, array $meta): Step
    {
        $key = InvestmentCatalogue::formKey($section);
        $items = $meta['items'];
        $chosen = fn (Get $get): array => (array) ($get("{$key}.chosen") ?? []);

        $fields = [];
        foreach ($items as $item => $info) {
            $itemKey = "{$section}.{$item}";
            $fields[] = TextInput::make("{$key}.amounts.{$item}")->label($info['label'])->prefix('₹')
                ->numeric()->minValue(1)->maxValue(self::MAX_AMOUNT)->required()->live(onBlur: true)
                ->helperText(fn () => $this->itemNote($itemKey) ?? 'Proof needed later: '.$info['proof'])
                ->disabled(fn () => $this->isLocked($itemKey))
                ->visible(fn (Get $get) => in_array($item, $chosen($get), true));
            if ($info['other'] ?? false) {
                $fields[] = TextInput::make("{$key}.other_description")->label('What is it?')->required()->maxLength(255)
                    ->disabled(fn () => $this->isLocked($itemKey))
                    ->visible(fn (Get $get) => in_array($item, $chosen($get), true));
            }
        }

        return Step::make($meta['step'])->icon(self::STEP_ICONS[$section])->description($meta['code'])->schema([
            $this->regimeCallout($section),
            Text::make($meta['intro']),
            Text::make(fn () => 'PF deducted from your salary so far this year: '.self::inr($this->pfSoFar()).'. It already counts towards this section’s limit.')
                ->visible(fn () => $section === '80C' && $this->pfSoFar() > 0),
            CheckboxList::make("{$key}.chosen")->label(count($items) > 1 ? 'Which of these apply to you this year? Tick all that apply.' : 'Does this apply to you this year?')
                ->options(collect($items)->map(fn (array $i) => $i['label'])->all())
                ->descriptions(collect($items)->map(fn (array $i) => $i['help'])->all())
                ->in(array_keys($items)) // a verified item stays ticked although its option is disabled
                ->disableOptionWhen(fn (string $value) => $this->isLocked("{$section}.{$value}"))
                ->columns(count($items) > 2 ? 2 : 1)->live(),
            Section::make(count($items) > 1 ? 'How much did you pay or invest in each?' : 'How much did you pay?')->compact()->columns(2)->schema($fields)
                ->visible(fn (Get $get) => $chosen($get) !== []),
            Text::make(fn (Get $get) => $this->meter($section, $get))->visible(fn (Get $get) => $chosen($get) !== []),
        ]);
    }

    private function previousEmployerStep(): Step
    {
        $worked = fn (Get $get) => (bool) $get('previous.worked');

        return Step::make('Previous job')->icon(Heroicon::OutlinedBriefcase)->description('If you joined this year')->schema([
            Text::make('If you worked for another employer earlier in this financial year, payroll needs that salary and the tax already deducted there, so your tax for the whole year is right. Skip this if this is your only job this year.'),
            Toggle::make('previous.worked')->label('I worked for another employer earlier in this financial year')->live(),
            Section::make()->compact()->columns(2)->visible($worked)->schema([
                TextInput::make('previous.income')->label('Taxable salary from the previous employer')->prefix('₹')->numeric()->minValue(0)->maxValue(self::MAX_AMOUNT)->required(),
                TextInput::make('previous.tds')->label('Income tax (TDS) they deducted')->prefix('₹')->numeric()->minValue(0)->maxValue(self::MAX_AMOUNT)->required(),
                Text::make('Find both on the Form 16 or the final payslip from your previous employer. Proof needed later: Form 16, Form 12B or the payslips.')->columnSpanFull(),
            ]),
        ]);
    }

    private function reviewStep(): Step
    {
        return Step::make('Review')->icon(Heroicon::OutlinedClipboardDocumentCheck)->description('Check and submit')->schema([
            Html::make(fn (Get $get) => view('filament.pages.declare-investments.review', $this->review($get))->render()),
            Checkbox::make('confirm')->label('I declare that these amounts are true, and I will give payroll the proofs when asked.')
                ->visible(fn () => $this->editable()),
        ]);
    }

    private function regimeCallout(string $section): Callout
    {
        return Callout::make('You chose the new regime')->warning()
            ->description('Under the new regime this section does not reduce your tax. You can still fill it in, or go back to Start to choose the old regime.')
            ->visible(fn (Get $get) => ! ($this->declarations()->counting((string) $get('financial_year'), (string) ($get('regime') ?: 'new'))[$section]['allowed'] ?? false));
    }

    /** "Declared ₹62,000 of the ₹1,50,000 that counts" with what is over the limit. */
    private function meter(string $section, Get $get): string
    {
        $declared = $this->sectionTotal($section, $get);
        $limit = $this->declarations()->counting((string) $get('financial_year'), 'old')[$section]['limit'];
        if ($limit === null) {
            return 'Total for this section: '.self::inr($declared).'. No upper limit.';
        }
        $line = 'Total for this section: '.self::inr($declared).' of the '.self::inr($limit).' that counts.';
        if ($section === '80C' && $this->pfSoFar() > 0) {
            $line .= ' Your PF also counts towards it.';
        }

        return $declared > $limit ? $line.' The '.self::inr($declared - $limit).' above the limit will not reduce your tax.' : $line;
    }

    /** @return array<string, mixed> data for the review partial */
    private function review(Get $get): array
    {
        $regime = (string) ($get('regime') ?: 'new');
        $counting = $this->declarations()->counting((string) $get('financial_year'), $regime);
        $rows = [];
        $proofs = [];
        foreach (InvestmentCatalogue::SECTIONS as $section => $meta) {
            $declared = $this->sectionTotal($section, $get);
            if ($declared <= 0) {
                continue;
            }
            $rule = $counting[$section];
            $rows[] = [
                'title' => $meta['title'],
                'declared' => self::inr($declared),
                'counts' => $section === 'HRA_RENT'
                    ? ($rule['allowed'] ? 'Payroll works out the tax-free part of your HRA from this' : 'Does not count in the new regime')
                    : (! $rule['allowed'] ? 'Does not count in the new regime' : ($rule['limit'] === null ? self::inr($declared).' (no limit)' : self::inr(min($declared, $rule['limit'])).' (limit '.self::inr($rule['limit']).')')),
                'over' => $rule['allowed'] && $rule['limit'] !== null && $declared > $rule['limit'],
            ];
            foreach ($this->chosenItems($section, $get) as $itemKey) {
                $proofs[] = InvestmentCatalogue::item($itemKey)['label'].': '.InvestmentCatalogue::item($itemKey)['proof'];
            }
        }
        if ($get('previous.worked')) {
            $proofs[] = 'Previous employer: Form 16, Form 12B or the payslips';
        }
        $warnings = [];
        foreach (InvestmentCatalogue::SECTIONS as $section => $meta) {
            $amounts = (array) ($get(InvestmentCatalogue::formKey($section).'.amounts') ?? []);
            foreach ($this->chosenItems($section, $get) as $itemKey) {
                $amount = $section === 'HRA_RENT' ? $this->rentAnnual($get) : (float) ($amounts[explode('.', $itemKey, 2)[1]] ?? 0);
                if ($amount <= 0) {
                    $warnings[] = 'You ticked “'.InvestmentCatalogue::item($itemKey)['label'].'” but did not enter an amount (step '.$meta['step'].').';
                }
            }
        }
        if ($get('rent.pays') && $this->rentAnnual($get) > 100000 && blank($get('rent.landlord_pan'))) {
            $warnings[] = 'Your rent is more than ₹1,00,000 a year, so payroll needs your landlord’s PAN (step Rent).';
        }

        return [
            'regime' => config("peopleos.compliance.tax_regimes.{$regime}", $regime),
            'isNew' => $regime === 'new',
            'rows' => $rows,
            'proofs' => $proofs,
            'warnings' => $warnings,
            'previous' => $get('previous.worked') ? ['income' => self::inr((float) $get('previous.income')), 'tds' => self::inr((float) $get('previous.tds'))] : null,
        ];
    }

    public function saveDraft(): void
    {
        $this->persist(submit: false);
    }

    public function submit(): void
    {
        $this->persist(submit: true);
    }

    private function persist(bool $submit): void
    {
        $employee = $this->employee();
        if ($employee === null) {
            return;
        }
        if (! $this->editable()) { // the steps are disabled, so the form has nothing to save
            Notification::make()->danger()->title($this->declaration()?->status === 'verified' ? 'Payroll has verified this declaration. Ask payroll to make any change.' : 'You cannot change this declaration.')->send();

            return;
        }
        $state = $this->form->getState();
        if ($submit && ! ($state['confirm'] ?? false)) {
            Notification::make()->warning()->title('Tick the confirmation in the Review step to submit')->send();

            return;
        }

        try {
            $this->declarations()->save(auth()->user(), $employee, (string) $state['financial_year'], $this->input($state), $submit);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->fillFor($employee->id, (string) $state['financial_year']);
        Notification::make()->success()
            ->title($submit ? 'Declaration submitted to payroll' : 'Draft saved')
            ->body($submit ? 'Payroll will use it for the tax on your coming salaries. You can still change it until payroll verifies it.' : 'Come back any time to finish it.')
            ->send();
    }

    /**
     * The form state as the service's input.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function input(array $state): array
    {
        $items = [];
        foreach (array_keys(InvestmentCatalogue::SECTIONS) as $section) {
            if ($section === 'HRA_RENT') {
                if ($state['rent']['pays'] ?? false) {
                    $rent = $state['rent'];
                    $items['HRA_RENT.rent'] = ['amount' => round((float) ($rent['monthly'] ?? 0) * (int) ($rent['months'] ?? 0), 2), 'details' => [
                        'monthly_rent' => (float) ($rent['monthly'] ?? 0), 'months' => (int) ($rent['months'] ?? 0),
                        'landlord_name' => $rent['landlord_name'] ?? null, 'landlord_pan' => filled($rent['landlord_pan'] ?? null) ? strtoupper($rent['landlord_pan']) : null, 'address' => $rent['address'] ?? null,
                    ]];
                }

                continue;
            }
            $form = $state[InvestmentCatalogue::formKey($section)] ?? [];
            foreach ((array) ($form['chosen'] ?? []) as $item) {
                $items["{$section}.{$item}"] = ['amount' => $form['amounts'][$item] ?? null, 'description' => $form['other_description'] ?? null];
            }
        }
        $worked = (bool) ($state['previous']['worked'] ?? false);

        return [
            'regime' => $state['regime'],
            'metro' => (bool) ($state['rent']['metro'] ?? false),
            'previous_employer_income' => $worked ? ($state['previous']['income'] ?? 0) : 0,
            'previous_employer_tds' => $worked ? ($state['previous']['tds'] ?? 0) : 0,
            'items' => $items,
        ];
    }

    /** Load the employee's declaration for the year into the form. */
    public function fillFor(?int $employeeId, string $year): void
    {
        $this->memoFor = null;
        $state = ['employee_id' => $employeeId, 'financial_year' => $year, 'regime' => 'new', 'rent' => ['pays' => false, 'months' => 12, 'metro' => false], 'previous' => ['worked' => false], 'confirm' => false];
        foreach (array_keys(InvestmentCatalogue::SECTIONS) as $section) {
            if ($section !== 'HRA_RENT') {
                $state[InvestmentCatalogue::formKey($section)] = ['chosen' => [], 'amounts' => []];
            }
        }
        $this->data = $state; // employee() and declaration() read the selection from here

        if ($this->employee() !== null) {
            $declaration = $this->declaration();
            $state['regime'] = $declaration?->regime ?? 'new';
            $income = (float) ($declaration?->previous_employer_income ?? 0);
            $tds = (float) ($declaration?->previous_employer_tds ?? 0);
            $state['previous'] = ['worked' => $income > 0 || $tds > 0, 'income' => $income ?: null, 'tds' => $tds ?: null];
            foreach ($this->items() as $key => $row) {
                $this->putItem($state, $key, (float) $row->declared_amount, $row->description, $row->details ?? []);
            }
            // Totals declared before items existed (or entered by payroll as one figure) open in the section's catch-all item.
            foreach (array_keys(InvestmentCatalogue::SECTIONS) as $section) {
                $total = $declaration?->amount($section) ?? 0;
                if ($total > 0 && ! $this->items()->contains('section', $section)) {
                    $this->putItem($state, InvestmentCatalogue::fallbackItem($section), $total, 'Declared earlier', []);
                }
            }
            $state['rent']['metro'] = ($declaration?->amount('METRO') ?? 0) > 0;
        }

        $this->form->fill($state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $details
     */
    private function putItem(array &$state, string $itemKey, float $amount, ?string $description, array $details): void
    {
        [$section, $item] = explode('.', $itemKey, 2);
        if ($section === 'HRA_RENT') {
            $months = max(1, min(12, (int) ($details['months'] ?? 12)));
            $state['rent'] = ['pays' => true, 'monthly' => $details['monthly_rent'] ?? round($amount / $months, 2), 'months' => $months, 'metro' => false,
                'landlord_name' => $details['landlord_name'] ?? null, 'landlord_pan' => $details['landlord_pan'] ?? null, 'address' => $details['address'] ?? null];

            return;
        }
        $key = InvestmentCatalogue::formKey($section);
        $state[$key]['chosen'][] = $item;
        $state[$key]['amounts'][$item] = $amount;
        if (InvestmentCatalogue::isOther($itemKey)) {
            $state[$key]['other_description'] = $description;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('draft')->label('Save and finish later')->icon(Heroicon::OutlinedBookmark)->color('gray')
                ->visible(fn () => $this->employee() !== null && $this->editable())
                ->action(fn () => $this->saveDraft()),
        ];
    }

    public function employee(): ?Employee
    {
        $this->memoise();

        return $this->employeeMemo;
    }

    public function declaration(): ?EmployeeTaxDeclaration
    {
        $this->memoise();

        return $this->declarationMemo;
    }

    /** @return Collection<string, TdsEmployeeInvestment> keyed by item key */
    private function items(): Collection
    {
        $this->memoise();

        return $this->itemsMemo;
    }

    private function memoise(): void
    {
        $id = (int) ($this->data['employee_id'] ?? 0);
        $year = (string) ($this->data['financial_year'] ?? '');
        if ($this->memoFor === "{$id}|{$year}") {
            return;
        }
        $this->memoFor = "{$id}|{$year}";
        $this->pfMemo = null;
        $this->employeeMemo = $id > 0 ? $this->declarations()->employeesFor(auth()->user())->with('person')->find($id) : null;
        $this->declarationMemo = $this->employeeMemo ? $this->declarations()->declaration($this->employeeMemo, $year) : null;
        $this->itemsMemo = $this->employeeMemo ? $this->declarations()->items($this->employeeMemo, $year)->keyBy('item_key') : collect();
    }

    public function editable(): bool
    {
        $employee = $this->employee();

        return $employee !== null && $this->declarations()->canEdit(auth()->user(), $employee, $this->declaration());
    }

    private function statusLine(): string
    {
        $declaration = $this->declaration();
        if ($this->employee() === null) {
            return 'Choose the employee whose declaration you are filing.';
        }

        return match ($declaration?->status) {
            'submitted' => 'Status: submitted to payroll on '.$declaration->updated_at?->format('j M Y').'. You can still change it.',
            'verified' => 'Status: verified by payroll.',
            'draft' => 'Status: draft, not yet sent to payroll.',
            default => 'Status: not started yet.',
        };
    }

    /** A verified proof is payroll's: the item can no longer be changed here. */
    private function isLocked(string $itemKey): bool
    {
        return $this->items()->get($itemKey)?->proof_status === 'verified';
    }

    /** Payroll's verdict on an item's proof, when it has one. */
    private function itemNote(string $itemKey): ?string
    {
        $row = $this->items()->get($itemKey);

        return match ($row?->proof_status) {
            'verified' => 'Payroll verified your proof for '.self::inr((float) $row->proof_amount).'. This item can no longer be changed.',
            'rejected' => 'Payroll did not accept the proof'.(filled($row->notes) ? ': '.$row->notes : '').'. Correct it and save to send it again.',
            default => null,
        };
    }

    private function pfSoFar(): float
    {
        $employee = $this->employee();

        return $employee === null ? 0.0 : $this->pfMemo ??= $this->declarations()->pfSoFar($employee, (string) $this->data['financial_year']);
    }

    /** @return list<string> item keys ticked in a section */
    private function chosenItems(string $section, Get $get): array
    {
        if ($section === 'HRA_RENT') {
            return $get('rent.pays') ? ['HRA_RENT.rent'] : [];
        }

        return array_map(fn ($item) => "{$section}.{$item}", (array) ($get(InvestmentCatalogue::formKey($section).'.chosen') ?? []));
    }

    private function sectionTotal(string $section, Get $get): float
    {
        if ($section === 'HRA_RENT') {
            return $get('rent.pays') ? $this->rentAnnual($get) : 0.0;
        }
        $key = InvestmentCatalogue::formKey($section);
        $amounts = (array) ($get("{$key}.amounts") ?? []);

        return round(collect((array) ($get("{$key}.chosen") ?? []))->sum(fn ($item) => max(0, (float) ($amounts[$item] ?? 0))), 2);
    }

    private function rentAnnual(Get $get): float
    {
        return round(max(0, (float) $get('rent.monthly')) * max(0, min(12, (int) $get('rent.months'))), 2);
    }

    private function declarations(): InvestmentDeclarations
    {
        return app(InvestmentDeclarations::class);
    }

    public static function inr(float $amount): string
    {
        $format = new NumberFormatter('en_IN', NumberFormatter::DECIMAL);
        $format->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return '₹'.$format->format($amount);
    }
}
