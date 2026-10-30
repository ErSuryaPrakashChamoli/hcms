<?php

namespace App\Filament\Pages;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Services\PolicySetup;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\PolicySettingsSchema;
use App\Filament\Support\RuleConditionsSchema;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * HROne setup parity: draft, change and publish any policy as a step-by-step wizard, the way HROne's policy screens
 * work (Policy → the policy's own sections → Applicability → Advance applicability → Review). The steps after "Policy"
 * come from the policy type's questions (config peopleos.policies.types, one step per section), so every policy type
 * gets the wizard. Saving goes through PolicySetup: one audited transaction for the policy, its draft version, its
 * applicability rules and, when asked, publishing.
 */
class PolicyWizard extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'policy-wizard';

    protected string $view = 'filament.pages.policy-wizard';

    #[Url]
    public ?string $type = null;

    #[Url(as: 'policy')]
    public ?int $policyId = null;

    /** The step shown, and the furthest step reached (earlier steps can be reopened from the step list). */
    public int $step = 0;

    public int $reached = 0;

    /** The policy's rules are not the plain ones the wizard edits: applicability stays with the rule editor. */
    public bool $advancedRules = false;

    /** The type whose questions the form was filled for (a new policy can change its type on the first step). */
    public ?string $filledType = null;

    /** @var array<string, mixed> */
    public array $data = [];

    /** Show and validate every step at once (saving). */
    private bool $allSteps = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('policy.create') || $user->can('policy.update'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $policy = $this->policy();
        if ($this->policyId !== null) {
            abort_if($policy === null, 404);
            abort_unless(auth()->user()->can('policy.update'), 403);
            $state = app(PolicySetup::class)->load($policy);
            $this->type = $policy->type;
            $this->advancedRules = (bool) $state['advanced_rules'];
            unset($state['advanced_rules']);
        } else {
            abort_unless(auth()->user()->can('policy.create'), 403);
            $this->type = array_key_exists((string) $this->type, (array) config('peopleos.policies.types')) ? $this->type : null;
            $state = ['type' => $this->type, 'status' => ActiveStatus::Active->value, 'settings' => [], 'applies_to' => 'everyone', 'applicability' => [],
                'employees' => [], 'effective_from' => now()->toDateString(), 'rules_until' => null];
        }
        $this->filledType = $this->type;
        $this->form->fill($state);
        $this->reached = $policy ? count($this->steps()) - 1 : 0;
    }

    public function getTitle(): string
    {
        if ($policy = $this->policy()) {
            return $policy->name;
        }
        $type = config('peopleos.policies.types.'.($this->data['type'] ?? $this->type).'.label');

        return $type ? "New {$type}" : 'New policy';
    }

    public function getSubheading(): ?string
    {
        $policy = $this->policy();
        if ($policy === null) {
            return 'Answer each step; you can save it as a draft and publish it when it is ready.';
        }
        $versions = app(PolicySetup::class)->versions($policy);

        return $policy->typeLabel().' · '.($versions['current'] ? "in force: {$versions['current']}" : 'not published yet')
            .($versions['draft'] ? " · editing draft v{$versions['draft']}" : ' · saving starts a new draft');
    }

    /** @return list<array{key: string, label: string, help: ?string}> the steps, for the step list */
    public function steps(): array
    {
        return array_map(fn (array $s) => ['key' => $s['key'], 'label' => $s['label'], 'help' => $s['help'] ?? null], $this->definitions());
    }

    public function form(Schema $schema): Schema
    {
        $groups = [];
        foreach ($this->definitions() as $i => $step) {
            $groups[] = Group::make($step['components'])->columns(2)->visible(fn () => $this->allSteps || $this->step === $i);
        }

        return $schema->statePath('data')->components($groups);
    }

    public function next(): void
    {
        $this->form->validate();
        if ($this->step === 0 && ($this->data['type'] ?? null) !== $this->filledType) {
            $this->changeType((string) $this->data['type']);
        }
        $this->step = min($this->step + 1, count($this->definitions()) - 1);
        $this->reached = max($this->reached, $this->step);
    }

    public function previous(): void
    {
        $this->step = max(0, $this->step - 1);
    }

    public function goTo(int $step): void
    {
        if ($step >= 0 && $step <= $this->reached && $step < count($this->definitions())) {
            $this->step = $step;
        }
    }

    public function isReview(): bool
    {
        return $this->step === count($this->definitions()) - 1;
    }

    public function canPublish(): bool
    {
        return auth()->user()?->can('policy.publish') ?? false;
    }

    public function saveDraft(): void
    {
        $this->persist(false);
    }

    public function publish(): void
    {
        abort_unless($this->canPublish(), 403);
        $this->persist(true);
    }

    /**
     * What the review step shows: the policy, its answers by step, and who it applies to.
     *
     * @return array{policy: list<array{0: string, 1: string}>, settings: list<array{label: string, rows: list<array{0: string, 1: string}>}>, applicability: list<array{0: string, 1: string}>}
     */
    public function review(): array
    {
        $type = (string) ($this->data['type'] ?? '');
        $fields = (array) config('peopleos.rules.fields');
        $applicability = $this->advancedRules ? [['Applies to', 'Set by the policy\'s assignment rules (rule editor)']] : [];
        if (! $this->advancedRules) {
            $appliesTo = (string) ($this->data['applies_to'] ?? 'everyone');
            $applicability[] = ['Applies to', PolicySetup::APPLIES_TO[$appliesTo] ?? $appliesTo];
            foreach ($appliesTo === 'groups' ? PolicySetup::DIMENSIONS : [] as $field) {
                $values = array_filter((array) ($this->data['applicability'][$field] ?? []));
                if ($values !== []) {
                    $options = RuleConditionsSchema::optionsFor($field);
                    $applicability[] = [$fields[$field]['label'] ?? $field, implode(', ', array_map(fn ($v) => (string) ($options[$v] ?? $v), $values))];
                }
            }
            $employees = array_values(array_filter((array) ($this->data['employees'] ?? [])));
            $applicability[] = ['Specific employees', $employees === [] ? '—' : implode(', ', RuleConditionsSchema::employees($employees))];
            $applicability[] = ['Rules end', filled($this->data['rules_until'] ?? null) ? (string) $this->data['rules_until'] : 'Open-ended'];
        }
        $applicability[] = ['Publish from', (string) ($this->data['effective_from'] ?? '—')];

        return [
            'policy' => [
                ['Kind', (string) config("peopleos.policies.types.{$type}.label", $type)],
                ['Name', (string) ($this->data['name'] ?? '')],
                ['Code', (string) ($this->data['code'] ?? '')],
                ['Status', (($s = $this->data['status'] ?? null) instanceof ActiveStatus ? $s : ActiveStatus::tryFrom((string) $s))?->getLabel() ?? '—'],
            ],
            'settings' => $type === '' ? [] : PolicySettingsSchema::summary($type, (array) ($this->data['settings'] ?? [])),
            'applicability' => $applicability,
        ];
    }

    public function rulesUrl(): ?string
    {
        $policy = $this->policy();

        return $policy ? PolicyResource::getUrl('edit', ['record' => $policy]) : null;
    }

    public function policy(): ?Policy
    {
        return $this->policyId === null ? null : Policy::query()->find($this->policyId);
    }

    private function persist(bool $publish): void
    {
        $state = $this->validated();
        try {
            $policy = app(PolicySetup::class)->save($state, $this->policy(), auth()->user(), $publish, (string) AuditReasonField::extract($state));
        } catch (ConfigurationException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();

            return;
        }
        $version = app(PolicySetup::class)->versions($policy);
        Notification::make()->success()
            ->title($publish ? "{$policy->name} published" : "{$policy->name} saved as draft v{$version['draft']}")
            ->body($publish ? "In force: {$version['current']}." : 'Publish it when it is ready.')
            ->send();
        $this->redirect(PolicyResource::getUrl('index'));
    }

    /**
     * Every step's answers, validated together; when a step fails, that step opens with its errors.
     *
     * @return array<string, mixed>
     */
    private function validated(): array
    {
        $this->allSteps = true;
        try {
            return $this->form->getState();
        } catch (ValidationException $all) {
            $this->allSteps = false;
            foreach (array_keys($this->definitions()) as $i) {
                $this->step = $i;
                $this->form->validate(); // the first step with errors stays open
            }

            throw $all;
        } finally {
            $this->allSteps = false;
        }
    }

    /** A new policy's type changed on the first step: start its questions afresh. */
    private function changeType(string $type): void
    {
        $this->type = $type;
        $this->filledType = $type;
        $this->cacheSchema('form');
        $this->form->fill(['settings' => []] + $this->data);
        $this->reached = 0;
    }

    /** @return list<array{key: string, label: string, help?: ?string, components: array<int, Component>}> */
    private function definitions(): array
    {
        $type = (string) ($this->data['type'] ?? $this->type ?? '');
        $known = array_key_exists($type, (array) config('peopleos.policies.types'));

        return [
            ['key' => 'policy', 'label' => 'Policy', 'help' => 'Name the policy and say what it is for.', 'components' => $this->policyStep()],
            ...($known ? PolicySettingsSchema::steps($type) : []),
            ['key' => 'applicability', 'label' => 'Applicability', 'help' => 'Who the policy applies to.', 'components' => $this->applicabilityStep()],
            ['key' => 'advance', 'label' => 'Advance applicability', 'help' => 'Named employees, and the dates it applies from and to.', 'components' => $this->advanceStep()],
            ['key' => 'review', 'label' => 'Review', 'help' => 'Check the answers, then save a draft or publish.', 'components' => [
                AuditReasonField::make()->required()->helperText('Recorded in the audit trail with the policy\'s before and after values.'),
            ]],
        ];
    }

    /** @return array<int, Component> */
    private function policyStep(): array
    {
        $new = $this->policyId === null;

        return [
            Select::make('type')->label('Kind of policy')->required()->native(false)->searchable()->live()
                ->options(collect((array) config('peopleos.policies.types'))->map(fn (array $t) => $t['label'])->sort()->all())
                ->disabled(! $new)->dehydrated()->columnSpanFull(),
            TextInput::make('name')->label('Policy name')->required()->maxLength(255)->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state, Set $set, Get $get) => $new && blank($get('code')) ? $set('code', Str::upper(Str::slug(Str::limit((string) $state, 30, ''), '_'))) : null),
            TextInput::make('code')->required()->maxLength(64)->alphaDash()->helperText('A short unique reference, e.g. ATT_GENERAL.'),
            Select::make('status')->options(ActiveStatus::class)->required()->native(false)->helperText('An inactive policy applies to nobody.'),
            Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
        ];
    }

    /** @return array<int, Component> */
    private function applicabilityStep(): array
    {
        if ($this->advancedRules) {
            return [];
        }
        $fields = (array) config('peopleos.rules.fields');
        $selects = array_map(fn (string $field) => Select::make("applicability.{$field}")->label($fields[$field]['label'] ?? $field)
            ->multiple()->searchable()->options(fn () => RuleConditionsSchema::optionsFor($field))
            ->visible(fn (Get $get) => $get('applies_to') === 'groups'), PolicySetup::DIMENSIONS);

        return [
            Radio::make('applies_to')->label('The policy applies to')->options(PolicySetup::APPLIES_TO)->required()->live()->columnSpanFull()
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                    if ($value === 'groups' && collect((array) $get('applicability'))->flatten()->filter(fn ($v) => filled($v))->isEmpty()) {
                        $fail('Choose at least one group below, or apply the policy to everyone.');
                    }
                })
                ->helperText('With groups, an employee must match every kind chosen (any of the values within a kind). Named employees, a group and everyone: the most specific policy wins.'),
            ...$selects,
        ];
    }

    /** @return array<int, Component> */
    private function advanceStep(): array
    {
        $rules = $this->advancedRules ? [] : [
            Select::make('employees')->label('Also apply to these employees')->multiple()->searchable()->columnSpanFull()
                ->getSearchResultsUsing(fn (string $search) => RuleConditionsSchema::employees(null, $search))
                ->getOptionLabelsUsing(fn (array $values) => RuleConditionsSchema::employees($values))
                ->helperText('Named employees get this policy whatever their group, ahead of group and everyone policies.'),
            DatePicker::make('rules_until')->label('Applies until')->native(false)->afterOrEqual('effective_from')
                ->helperText('Leave empty to keep applying it. After this date the policy no longer applies to anyone.'),
        ];

        return [
            DatePicker::make('effective_from')->label('Publish from')->native(false)->required()
                ->helperText('When published, this version takes effect on this date and the version before ends the day before.'),
            ...$rules,
        ];
    }
}
