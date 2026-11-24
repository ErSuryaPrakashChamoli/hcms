<?php

namespace App\Filament\Support\Cloning;

use App\Domain\Configuration\Cloning\Adapters\DescribesColumns;
use App\Domain\Configuration\Cloning\ApplicabilityKind;
use App\Domain\Configuration\Cloning\ApplicabilityRewriter;
use App\Domain\Configuration\Cloning\CloneAdapter;
use App\Domain\Configuration\Cloning\ClonePlan;
use App\Domain\Configuration\Cloning\CloneRefusedException;
use App\Domain\Configuration\Cloning\CloneRegistry;
use App\Domain\Configuration\Cloning\CloneRequest;
use App\Domain\Configuration\Cloning\CloneScope;
use App\Domain\Configuration\Cloning\ConfigurationCloner;
use App\Domain\Configuration\Cloning\Destination;
use App\Domain\Configuration\Cloning\OrgUnitMapper;
use App\Domain\Configuration\Cloning\Reference;
use App\Domain\Configuration\Models\ConfigurationLineage;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Filament\Support\AuditReasonField;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The Clone action of a configuration type (configuration cloning, Deliverable 5): one line per resource,
 * CloneAction::make('policy'), in the row actions and the record header. A guided wizard on the shared engine —
 * Source, Companies, Dependencies, Applicability, Review — that creates inactive copies under new codes, limited to the
 * companies chosen. The list stays put; the notice carries Open for each copy, where it is finished and activated.
 */
final class CloneAction
{
    /** @var array<string, ClonePlan|string> this request's plans (or why there is none), by what was asked */
    private static array $plans = [];

    public static function make(string $adapter, string $name = 'clone'): Action
    {
        return Action::make($name)
            ->label('Clone')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->visible(fn () => ($user = auth()->user()) instanceof User && app(CloneScope::class)->mayClone($user, self::adapter($adapter)))
            ->modalHeading(fn (Model $record) => 'Clone '.(string) ($record->getAttribute(self::nameColumn(self::adapter($adapter))) ?? self::adapter($adapter)->label()))
            ->modalDescription('The copy is created inactive under its own code, for the companies you choose. You activate it afterwards, the way you publish anything new. The original does not change, and later changes to either never reach the other.')
            ->modalSubmitActionLabel('Create inactive copy')
            ->modalWidth(Width::FiveExtraLarge)
            ->fillForm(fn (Model $record) => self::initialState($adapter, $record))
            ->steps(fn (Model $record) => self::steps($adapter, $record))
            ->action(function (Model $record, array $data, Action $action) use ($adapter) {
                try {
                    $result = app(ConfigurationCloner::class)->execute(self::request($adapter, $record, $data), auth()->user());
                } catch (CloneRefusedException $refused) {
                    Notification::make()->danger()->title('Not cloned')->body($refused->getMessage())->send();
                    $action->halt();

                    return;
                }
                $copies = $result->copies;
                $todo = collect((array) ($result->operation->result['notes'] ?? []))->flatten()->filter()->unique()->values();
                Notification::make()->success()
                    ->title(count($copies) === 1 ? 'Created '.$copies[0]->getAttribute(self::nameColumn(self::adapter($adapter))).' (inactive)' : 'Created '.count($copies).' inactive copies')
                    ->body($result->repeated ? 'This clone had already been made; nothing was copied twice.'
                        : 'Open each copy to finish it and publish it when it is ready.'.($todo->isEmpty() ? '' : ' Still to do: '.$todo->implode(' ')))
                    ->actions(collect($copies)->take(5)->map(fn (Model $copy) => Action::make('open'.$copy->getKey())->label('Open '.$copy->getAttribute(self::codeColumn(self::adapter($adapter))))
                        ->url(CloneLinks::open($adapter, (int) $copy->getKey())))->all())
                    ->persistent()
                    ->send();
            });
    }

    /** @return list<Step> */
    private static function steps(string $key, Model $record): array
    {
        $adapter = self::adapter($key);
        $options = $adapter->sourceOptions($record);
        $user = auth()->user();
        $scope = app(CloneScope::class);
        $cross = $user instanceof User && $scope->mayCrossCompanies($user);
        $ruleBased = $adapter->applicability() === ApplicabilityKind::RuleConditions;

        return [
            Step::make('Source')->description('What is copied')->schema([
                Hidden::make('idempotency_key'),
                Hidden::make('source_checksum'),
                Text::make(fn (Get $get) => self::sourcePanel($adapter, $record, (array) ($get('options') ?? [])))->columnSpanFull(),
                ...array_map(fn (string $name) => Select::make("options.{$name}")->label($options[$name]['label'])->options($options[$name]['options'])
                    ->helperText($options[$name]['help'] ?? null)->required()->native(false)->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('source_checksum', $adapter->snapshot($record, (array) ($get('options') ?? []))->checksum())), array_keys($options)),
            ]),
            Step::make('Companies')->description($cross ? 'Where the copies go' : 'Where the copy goes')->schema([
                Repeater::make('destinations')->hiddenLabel()
                    ->schema([
                        Select::make('company_id')->label('Company')->required()->native(false)->live()
                            ->options(fn () => self::destinationOptions($adapter, $record))
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                            ->afterStateUpdated(function ($state, Set $set) use ($adapter, $record) {
                                [$name, $code] = self::proposal($adapter, $record, filled($state) ? (int) $state : null);
                                $set('name', $name);
                                $set('code', $code);
                            }),
                        TextInput::make('name')->label('Name of the copy')->required()->maxLength(255),
                        TextInput::make('code')->label('Code of the copy')->required()->maxLength(64)->alphaDash()
                            ->uniqueCode($adapter->model(), self::codeColumn($adapter), ignore: fn () => 0),
                        TextInput::make('priority')->label('Priority')->integer()->minValue(0)->maxValue(100000)->placeholder('As the original')
                            ->helperText('Lower numbers win when several apply.')->visible($ruleBased),
                    ])
                    ->columns($ruleBased ? 4 : 3)
                    ->minItems(1)
                    ->maxItems($cross ? (int) config('peopleos.cloning.limits.destinations', 10) : 1)
                    ->addable($cross)->deletable($cross)->reorderable(false)
                    ->addActionLabel('Add another company')
                    ->live()
                    ->helperText($cross ? 'One independent copy per company, each with its own code. All are made together, or none.'
                        : 'You may clone into a company this already applies to. Cloning into other companies needs the cross-company clone permission.'),
            ]),
            Step::make('Dependencies')->description('What it points at')->schema([
                Text::make(fn (Get $get) => self::panel('references', ['references' => self::references($adapter, $record, $get)]))->columnSpanFull(),
            ])->visible(fn (Get $get) => self::references($adapter, $record, $get) !== []),
            Step::make('Applicability')->description('Who each copy applies to')->schema([
                Group::make(fn (Get $get) => self::unitFields($adapter, $record, $get))->columns(2)->columnSpanFull(),
                Text::make(fn (Get $get) => self::applicabilityPanel($key, $record, $get))->columnSpanFull(),
            ])->visible($adapter->applicability() !== ApplicabilityKind::None),
            Step::make('Review')->description('Check, then create')->schema([
                Text::make(fn (Get $get) => self::reviewPanel($key, $record, $get))->columnSpanFull(),
                Checkbox::make('acknowledge')->label('I have read the warnings')
                    ->visible(fn (Get $get) => ($plan = self::plan($key, $record, $get)) instanceof ClonePlan && $plan->warnings() !== [] && $plan->blockers() === [])
                    ->accepted(),
                AuditReasonField::make()->required()->helperText('Recorded in the audit trail with what was copied, where to, and every choice made.'),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private static function initialState(string $key, Model $record): array
    {
        $adapter = self::adapter($key);
        $options = collect($adapter->sourceOptions($record))->map(fn (array $o) => $o['default'])->all();
        $companies = self::destinationOptions($adapter, $record);
        $reach = app(ConfigurationCloner::class)->sourceCompanies($adapter, $record, $options);
        $first = collect(array_keys($companies))->first(fn (int $id) => $reach === null || in_array($id, $reach, true)) ?? array_key_first($companies);
        [$name, $code] = self::proposal($adapter, $record, $first);

        return [
            'idempotency_key' => (string) Str::ulid(),
            'source_checksum' => $adapter->snapshot($record, $options)->checksum(),
            'options' => $options,
            'destinations' => [['company_id' => $first, 'name' => $name, 'code' => $code, 'priority' => null]],
            'unit_map' => [],
            'acknowledge' => false,
        ];
    }

    /** @param  array<string, mixed>  $state */
    private static function request(string $adapter, Model $record, array $state): CloneRequest
    {
        $destinations = collect((array) ($state['destinations'] ?? []))
            ->filter(fn ($d) => is_array($d) && filled($d['company_id'] ?? null))
            ->map(fn (array $d) => new Destination((int) $d['company_id'], trim((string) ($d['name'] ?? '')), trim((string) ($d['code'] ?? '')),
                filled($d['priority'] ?? null) ? (int) $d['priority'] : null))
            ->values()->all();
        $choices = [];
        foreach ((array) ($state['unit_map'] ?? []) as $company => $fields) {
            foreach ((array) $fields as $field => $values) {
                foreach ((array) $values as $id => $choice) {
                    if (filled($choice)) {
                        $choices[(int) $company][(string) $field][(int) $id] = $choice === CloneRequest::DROP ? CloneRequest::DROP : (int) $choice;
                    }
                }
            }
        }

        return new CloneRequest($adapter, (int) $record->getKey(), $destinations, array_filter((array) ($state['options'] ?? []), fn ($v) => filled($v)), $choices,
            (string) ($state[AuditReasonField::NAME] ?? ''), filled($state['idempotency_key'] ?? null) ? (string) $state['idempotency_key'] : null,
            filled($state['source_checksum'] ?? null) ? (string) $state['source_checksum'] : null, (bool) ($state['acknowledge'] ?? false));
    }

    /** The plan for what the wizard holds now (or why there is none), worked out once per request. */
    private static function plan(string $adapter, Model $record, Get $get): ClonePlan|string
    {
        $request = self::request($adapter, $record, ['destinations' => $get('destinations'), 'options' => $get('options'), 'unit_map' => $get('unit_map')]);
        if ($request->destinations === []) {
            return 'Choose a company first.';
        }
        $memo = md5((string) json_encode([$request->toArray(), auth()->id()]));
        if (! array_key_exists($memo, self::$plans)) {
            try {
                self::$plans[$memo] = app(ConfigurationCloner::class)->plan($request, auth()->user());
            } catch (CloneRefusedException $refused) {
                self::$plans[$memo] = $refused->getMessage();
            }
        }

        return self::$plans[$memo];
    }

    /**
     * What the source points at (no plan needed: the Dependencies step shows only when there is something).
     *
     * @return list<Reference>
     */
    private static function references(CloneAdapter $adapter, Model $record, Get $get): array
    {
        return $adapter->references($adapter->snapshot($record, array_filter((array) ($get('options') ?? []), fn ($v) => filled($v))));
    }

    /** @param  array<string, mixed>  $options */
    private static function sourcePanel(CloneAdapter $adapter, Model $record, array $options): HtmlString
    {
        $snapshot = $adapter->snapshot($record, array_filter($options, fn ($v) => filled($v)));

        return self::panel('source', [
            'rows' => array_slice(CloneLinks::rows($snapshot), 0, 4),
            'rules' => CloneLinks::rules($snapshot->applicability),
            'notCopied' => (int) ($snapshot->applicability['not_copied'] ?? 0),
            'copies' => ConfigurationLineage::query()->where('adapter', $adapter->key())->where('role', ConfigurationLineage::ROOT)->where('source_id', $record->getKey())->count(),
            'rulesUrl' => CloneLinks::record($adapter->key(), (int) $record->getKey()),
        ]);
    }

    private static function applicabilityPanel(string $key, Model $record, Get $get): HtmlString
    {
        $plan = self::plan($key, $record, $get);
        if (! $plan instanceof ClonePlan) {
            return self::panel('applicability', ['error' => $plan, 'companies' => []]);
        }
        $names = self::companyNames($plan->request->companyIds());
        $companies = [];
        foreach ($plan->request->destinations as $destination) {
            $id = $destination->companyId;
            $impact = $plan->impact[$id] ?? null;
            $companies[] = [
                'name' => $names[$id] ?? "#{$id}",
                'after' => CloneLinks::rules($plan->applicabilityFor($id)),
                'notes' => (array) ($plan->applicability[$id]['notes'] ?? []),
                'impact' => $impact === null ? null : ($impact['matched'] === 1 ? '1 person' : "{$impact['matched']} people").' in '.($names[$id] ?? 'the company').' match today'
                    .($impact['matched'] > 0 ? ", of whom {$impact['winning']} would get the copy once it is published" : '').($impact['capped'] ? ' (counted on the first '.$impact['employees'].')' : '').'.',
            ];
        }

        return self::panel('applicability', ['error' => null, 'companies' => $companies]);
    }

    private static function reviewPanel(string $key, Model $record, Get $get): HtmlString
    {
        $plan = self::plan($key, $record, $get);
        if (! $plan instanceof ClonePlan) {
            return self::panel('review', ['error' => $plan, 'blockers' => [], 'warnings' => [], 'copies' => []]);
        }
        $names = self::companyNames($plan->request->companyIds());

        return self::panel('review', [
            'error' => null, 'blockers' => $plan->blockers(), 'warnings' => $plan->warnings(),
            'copies' => array_map(fn (Destination $d) => "{$d->name} ({$d->code}) for ".($names[$d->companyId] ?? "#{$d->companyId}"), $plan->request->destinations),
        ]);
    }

    /**
     * A choice for every unit of another company that the source's rules name, per destination: the destination's own
     * unit (the same-named one is suggested) or removing it from the rule.
     *
     * @return list<Select>
     */
    private static function unitFields(CloneAdapter $adapter, Model $record, Get $get): array
    {
        if ($adapter->applicability() !== ApplicabilityKind::RuleConditions) {
            return [];
        }
        $snapshot = $adapter->snapshot($record, array_filter((array) ($get('options') ?? []), fn ($v) => filled($v)));
        $units = app(OrgUnitMapper::class);
        $companies = collect((array) ($get('destinations') ?? []))->pluck('company_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $names = self::companyNames($companies->all());
        $fields = [];
        foreach ($companies as $company) {
            foreach ((array) ($snapshot->applicability['rules'] ?? []) as $rule) {
                foreach ((array) ($rule['conditions'] ?? []) as $condition) {
                    $field = (string) ($condition['field'] ?? '');
                    if (ApplicabilityRewriter::classify($field) !== 'unit' || in_array($condition['operator'] ?? '', ['is_empty', 'is_not_empty'], true)) {
                        continue;
                    }
                    foreach ((array) ($condition['value'] ?? []) as $id) {
                        $owner = $units->companyOf($field, $id);
                        if ($owner === null || $owner === $company || isset($fields["{$company}.{$field}.{$id}"])) {
                            continue;
                        }
                        $suggested = $units->suggest($field, $id, $company);
                        $label = (string) config("peopleos.rules.fields.{$field}.label", $field);
                        $fields["{$company}.{$field}.{$id}"] = Select::make("unit_map.{$company}.{$field}.{$id}")
                            ->label("{$label} \"{$units->name($field, $id)}\" → in ".($names[$company] ?? 'the company'))
                            ->options([CloneRequest::DROP => 'Remove it from the rule'] + $units->options($field, $company))
                            ->placeholder($suggested !== null ? 'Suggested: '.$units->name($field, $suggested) : "Choose the {$label}")
                            ->required($suggested === null)
                            ->native(false)->searchable()->live()
                            ->helperText($suggested !== null ? 'Leave it to use the suggestion.' : 'No '.strtolower($label).' of the same name there: choose one, or remove it (the rule then reaches more people).');
                    }
                }
            }
        }

        return array_values($fields);
    }

    /** @return array<int, string> the companies the person may clone this into */
    private static function destinationOptions(CloneAdapter $adapter, Model $record): array
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return [];
        }
        $reach = app(ConfigurationCloner::class)->sourceCompanies($adapter, $record);

        return app(CloneScope::class)->destinations($user, $adapter, $reach)->mapWithKeys(fn (Company $c) => [(int) $c->id => (string) $c->name])->all();
    }

    /**
     * The proposed name and code of a copy: <SOURCE CODE>_<COMPANY CODE> (decision OD-7), made unique if taken.
     *
     * @return array{0: string, 1: string}
     */
    private static function proposal(CloneAdapter $adapter, Model $record, ?int $companyId): array
    {
        $company = $companyId === null ? null : Company::query()->withoutGlobalScope(AccessScope::class)->find($companyId);
        $name = (string) $record->getAttribute(self::nameColumn($adapter));
        $code = (string) $record->getAttribute(self::codeColumn($adapter));
        $reach = app(ConfigurationCloner::class)->sourceCompanies($adapter, $record);
        $variation = $company === null || $reach === null || in_array((int) $company->id, $reach, true);
        $suffix = Str::upper(Str::slug((string) ($company?->code ?: 'COPY'), '_')) ?: 'COPY';
        $base = Str::limit($code.'_'.$suffix, 60, '');
        $proposed = $base;
        for ($i = 2; $adapter->codeTaken($proposed) && $i < 100; $i++) {
            $proposed = "{$base}_{$i}";
        }

        return [Str::limit($name.' ('.($variation && $reach !== null ? 'copy' : ($company?->name ?? 'copy')).')', 255, ''), $proposed];
    }

    /** @return array<int, string> */
    private static function companyNames(array $ids): array
    {
        return Company::query()->withoutGlobalScope(AccessScope::class)->whereKey($ids)->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])->all();
    }

    /** The column naming the type's records (configuration types may use another column than name). */
    private static function nameColumn(CloneAdapter $adapter): string
    {
        return $adapter instanceof DescribesColumns ? $adapter->nameColumn() : 'name';
    }

    /** The column holding the type's unique code (onboarding templates and workflows use key). */
    private static function codeColumn(CloneAdapter $adapter): string
    {
        return $adapter instanceof DescribesColumns ? $adapter->codeColumn() : 'code';
    }

    private static function adapter(string $key): CloneAdapter
    {
        return app(CloneRegistry::class)->for($key);
    }

    /** @param  array<string, mixed>  $data */
    private static function panel(string $view, array $data): HtmlString
    {
        return new HtmlString(view("filament.cloning.{$view}", $data)->render());
    }
}
