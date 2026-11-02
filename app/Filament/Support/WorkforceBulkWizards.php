<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\EmployeeSelection;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\BulkInvitations;
use App\Domain\Identity\Services\RoleGrants;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * HROne parity WO-16: the step wizards for "Change manager" (§23.9 #4–5), "Invite to sign in" (#7–8, #10) and
 * "Send password reset links" (#17) on the Employees register, the Users list, a manager's employee page and the
 * Workforce desk (which opens them with `?action=`).
 *
 * The wizards only choose WHO (a manager's team, the WO-2 filters, pasted codes, or what the Employees register shows
 * now) and WHAT, show a review with the count, and start a WO-1 bulk run (BulkRuns::start: bulk.run + the operation's
 * permission, the typed count above `bulk.confirm_over`, queued, one outcome per record). Every record is still checked
 * on its own when the run acts on it (access scope, the operation's refusals, the owning service).
 */
final class WorkforceBulkWizards
{
    public const CHANGE_MANAGER = 'employees.change_manager';

    public const INVITE = 'employees.invite_users';

    public const RESET = 'employees.send_reset_links';

    /** Filters of the "Who" step (form field => [label, model]); all multi-selects on the current position. */
    private const ORGANISATION = [
        'company_id' => ['Company', Company::class], 'business_unit_id' => ['Business unit', BusinessUnit::class], 'division_id' => ['Division', Division::class],
        'department_id' => ['Department (with its sub-departments)', Department::class], 'designation_id' => ['Designation', Designation::class],
        'grade_id' => ['Grade', Grade::class], 'level_id' => ['Level', Level::class], 'location_id' => ['Location', Location::class],
        'employment_type_id' => ['Employment type', EmploymentType::class],
    ];

    /** The person's change, asked once (the "Change" step; also the bulk action's form on rows ticked in the register). */
    public static function managerChangeFields(): array
    {
        return [
            self::employeeSelect('manager_id', 'New manager', true)->required()->live(),
            Select::make('type')->label('Relationship that changes')->options(fn () => (array) config('peopleos.people.reporting_types'))->default('line')->required()->live(),
            DatePicker::make('effective_from')->label('Effective from')->default(fn () => now()->toDateString())->required()
                ->helperText('The current reporting line ends the day before and stays in the history.'),
            Toggle::make('reassign_tasks')->label('Move their open approvals to the new manager')->default(false)
                ->helperText('Only approvals waiting on the old manager because a workflow step chose the employee\'s line (or functional) manager. Anything the old manager holds for another reason stays. For a change effective today or earlier.')
                ->visible(fn () => auth()->user()?->hasPermission('task.reassign') ?? false),
            Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(500),
        ];
    }

    /** CC addresses (and, for new sign-ins, roles): the bulk action's form on rows ticked; `new` | `reset` | `cc`. */
    public static function invitationFields(string $mode): array
    {
        $fields = [self::ccField()];
        if ($mode === 'new') {
            $fields[] = self::rolesField();
        }

        return $fields;
    }

    /**
     * "Change manager": Who → Change → Review. With $teamOf (a manager's employee page) the team of that employee moves;
     * the Workforce desk may pass `?actionArguments[team]=<employee id>` for the same.
     */
    public static function changeManager(string $name = 'bulkChangeManager', ?Closure $teamOf = null): Action
    {
        return Action::make($name)
            ->label($teamOf !== null ? 'Change manager of the team' : 'Change manager')
            ->icon(Heroicon::OutlinedUserGroup)
            ->color('gray')
            ->visible(function () use ($teamOf) {
                if (! self::allowed(self::CHANGE_MANAGER)) {
                    return false;
                }
                $manager = $teamOf !== null ? $teamOf() : null;

                return $teamOf === null || ($manager instanceof Employee && app(EmployeeSelection::class)->team($manager)->exists());
            })
            ->modalHeading($teamOf !== null ? 'Change manager of the team' : 'Change manager')
            ->modalDescription('Move people to a new manager from a date. Each person is changed on their own and the earlier reporting line stays in the history; anyone who cannot move is listed with the reason.')
            ->modalSubmitActionLabel('Start')
            ->fillForm(function (array $arguments) use ($teamOf) {
                $team = $teamOf !== null ? $teamOf() : (isset($arguments['team']) ? Employee::query()->find((int) $arguments['team']) : null);

                return [
                    'source' => $team instanceof Employee ? 'team' : 'filters',
                    'team_of' => $team?->getKey(),
                    'team_type' => 'line',
                    'type' => 'line',
                    'effective_from' => self::defaultDate($team),
                    'reassign_tasks' => false,
                ];
            })
            ->steps(fn ($livewire) => [
                Step::make('Who')->description('A manager\'s team, filters or codes')->schema(self::whoFields($livewire, 'manager', $teamOf !== null)),
                Step::make('Change')->description('New manager and from when')->schema(self::managerChangeFields()),
                Step::make('Review')->description('Who moves, then confirm')->schema(self::reviewFields($livewire, 'manager')),
            ])
            ->action(fn ($livewire, array $data) => self::start($livewire, self::CHANGE_MANAGER, $data, ['manager_id', 'type', 'effective_from', 'reassign_tasks', 'reason']));
    }

    /** "Invite to sign in" (mode chosen) or, with $fixedMode `reset`, "Send password reset links": What → Who → Review. */
    public static function invite(string $name = 'bulkInvite', ?string $fixedMode = null): Action
    {
        $modeFor = fn (): string => $fixedMode ?? (self::allowed(self::INVITE) ? 'new' : 'reset');

        return Action::make($name)
            ->label($fixedMode === 'reset' ? 'Send password reset links' : 'Invite to sign in')
            ->icon($fixedMode === 'reset' ? Heroicon::OutlinedKey : Heroicon::OutlinedEnvelopeOpen)
            ->color('gray')
            ->visible(fn () => $fixedMode === 'reset' ? self::allowed(self::RESET) : (self::allowed(self::INVITE) || self::allowed(self::RESET)))
            ->modalHeading($fixedMode === 'reset' ? 'Send password reset links' : 'Invite employees to sign in')
            ->modalDescription('Uses the same invitation and reset e-mails as Users. You never see a link or a password; anyone who cannot be sent one is listed with the reason, and the run keeps a delivery list.')
            ->modalSubmitActionLabel('Start')
            ->fillForm(fn () => [
                'mode' => $modeFor(),
                'source' => 'filters',
                'sign_in' => $modeFor() === 'reset' ? ['active'] : ['none', 'invited'],
                'roles' => app(BulkInvitations::class)->defaultRoleIds(auth()->user()),
                'cc' => [],
            ])
            ->steps(fn ($livewire) => [
                Step::make($fixedMode === 'reset' ? 'Copy' : 'Invitation')->description($fixedMode === 'reset' ? 'Who else is told' : 'New sign-ins or reset links')->schema([
                    Radio::make('mode')->label('What to send')->required()->live()
                        ->options(fn () => array_filter([
                            'new' => self::allowed(self::INVITE) ? BulkInvitations::MODES['new'].' — create a sign-in from the work e-mail and send the invitation' : null,
                            'reset' => self::allowed(self::RESET) ? BulkInvitations::MODES['reset'].' — send the password reset link' : null,
                        ]))
                        ->visible($fixedMode === null)->dehydratedWhenHidden()
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('sign_in', $state === 'reset' ? ['active'] : ['none', 'invited'])),
                    self::ccField(),
                    self::rolesField()->visible(fn (Get $get) => $get('mode') === 'new' && (auth()->user()?->hasPermission('user.assign_roles') ?? false)),
                ]),
                Step::make('Who')->description('Filters or codes')->schema(self::whoFields($livewire, 'invite', false)),
                Step::make('Review')->description('Who is sent what, then confirm')->schema(self::reviewFields($livewire, 'invite')),
            ])
            ->action(fn ($livewire, array $data) => self::start($livewire, ($data['mode'] ?? $modeFor()) === 'reset' ? self::RESET : self::INVITE, $data, ['cc', 'roles']));
    }

    /** A searchable employee picker (employed people only for a new manager). */
    public static function employeeSelect(string $name, string $label, bool $employedOnly): Select
    {
        $base = fn (): Builder => Employee::query()->with('person')->when($employedOnly, fn (Builder $q) => $q->employed());

        return Select::make($name)->label($label)->searchable()
            ->options(fn () => self::employeeOptions($base()))
            ->getSearchResultsUsing(fn (string $search) => self::employeeOptions($base()->where(fn (Builder $q) => EmployeeFilters::search()($q, $search))))
            ->getOptionLabelUsing(fn ($value) => Employee::query()->with('person')->find((int) $value)?->auditLabel());
    }

    /**
     * The employees a wizard's data chooses (ids, at most `bulk.max_records` + 1 so an oversize choice is refused).
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    public static function selectedIds(object $livewire, array $data): array
    {
        $limit = BulkRuns::maxRecords() + 1;
        if (($data['source'] ?? null) === 'register') {
            if (! $livewire instanceof ListEmployees || ! BulkRecordActions::isNarrowed($livewire)) {
                throw new RuntimeException('Apply a filter or a search on the register first.');
            }
            $query = $livewire->getFilteredTableQuery();

            return $query->toBase()->limit($limit)->pluck($query->getModel()->getQualifiedKeyName())->map(fn ($id) => (int) $id)->all();
        }

        return app(EmployeeSelection::class)->ids($data, $limit);
    }

    /**
     * The "Who" fields (a manager's team for `manager`, the WO-2 filters, pasted codes, the register's view). HROne parity
     * WO-14 reuses them (purpose `share`) for a document shared with everyone chosen.
     *
     * @return list<mixed>
     */
    public static function whoFields(object $livewire, string $purpose, bool $teamFixed): array
    {
        $sources = array_filter([
            'team' => $purpose === 'manager' ? EmployeeSelection::SOURCES['team'] : null,
            'filters' => EmployeeSelection::SOURCES['filters'],
            'codes' => EmployeeSelection::SOURCES['codes'],
            'register' => $livewire instanceof ListEmployees && BulkRecordActions::isNarrowed($livewire) ? 'The employees this register shows now (its filters and search)' : null,
        ]);
        $is = fn (string $source) => fn (Get $get) => $get('source') === $source;
        $organisation = [];
        foreach (self::ORGANISATION as $field => [$label, $model]) {
            $organisation[] = Select::make($field)->label($label)->multiple()->searchable()->preload()
                ->options(fn () => $model::query()->orderBy('name')->limit(1000)->pluck('name', 'id')->all());
        }

        return [
            Radio::make('source')->label('Who')->options($sources)->required()->live()->visible(! $teamFixed)->dehydratedWhenHidden(),
            Grid::make(2)->visible($is('team'))->schema([
                self::employeeSelect('team_of', 'Current manager', false)->required($is('team'))->visible(! $teamFixed)->dehydratedWhenHidden($teamFixed)->live(),
                Radio::make('team_type')->label('Team')->options(EmployeeSelection::TEAM_TYPES)->default('line')->required($is('team'))->live(),
            ]),
            Grid::make(2)->visible($is('filters'))->schema([
                ...$organisation,
                Select::make('lifecycle_state')->label('Status')->multiple()
                    ->options(collect(LifecycleState::cases())->mapWithKeys(fn (LifecycleState $s) => [$s->value => $s->getLabel()])->all()),
                Select::make('sign_in')->label('Sign-in')->multiple()->options(EmployeeSelection::SIGN_IN)->visible($purpose === 'invite'),
                DatePicker::make('joined_from')->label('Joining date from'),
                DatePicker::make('joined_until')->label('Joining date until'),
                DatePicker::make('created_from')->label('Added on from'),
                DatePicker::make('created_until')->label('Added on until'),
                Toggle::make('everyone')->label('Everyone in my access (no other filter needed)')->columnSpanFull(),
            ]),
            Textarea::make('codes')->label('Employee codes')->rows(3)->placeholder('e.g. EMP001, EMP002')->visible($is('codes'))->required($is('codes'))
                ->helperText('Up to '.max(1, (int) config('peopleos.bulk.pasted_codes_max', 1000)).' codes, separated by commas, spaces or new lines.'),
        ];
    }

    /** @return list<mixed> */
    private static function reviewFields(object $livewire, string $purpose): array
    {
        $count = fn (Get $get): ?int => rescue(fn () => count(self::selectedIds($livewire, self::state($get))), null, false);

        return [
            Text::make(fn (Get $get) => self::summary($livewire, $get, $purpose))->columnSpanFull(),
            TextInput::make('confirm_count')
                ->label(fn (Get $get) => 'Type '.($count($get) ?? 0).' to confirm')
                ->helperText('More than '.BulkRuns::confirmOver().' employees: type the number to confirm. Anyone who cannot be done is listed in the result, not changed.')
                ->required(fn (Get $get) => BulkRuns::needsTypedCount((int) $count($get)))
                ->visible(fn (Get $get) => BulkRuns::needsTypedCount((int) $count($get)))
                ->in(fn (Get $get) => [(string) $count($get)])->validationMessages(['in' => 'Type exactly the number of employees.']),
        ];
    }

    /** @return array<string, mixed> the "Who" data as the selection reads it */
    public static function state(Get $get): array
    {
        $state = [];
        foreach (['source', 'team_of', 'team_type', 'codes', 'lifecycle_state', 'sign_in', 'joined_from', 'joined_until', 'created_from', 'created_until', 'everyone', ...array_keys(self::ORGANISATION)] as $key) {
            $state[$key] = $get($key);
        }

        return $state;
    }

    private static function summary(object $livewire, Get $get, string $purpose): HtmlString|string
    {
        try {
            $ids = self::selectedIds($livewire, self::state($get));
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        if ($ids === []) {
            return 'Nobody matches: go back and change who it is for.';
        }
        if (count($ids) > BulkRuns::maxRecords()) {
            return 'More than '.BulkRuns::maxRecords().' employees match: narrow the choice.';
        }
        $preview = Employee::query()->with(['person', 'currentManager.manager.person', 'user'])->whereKey(array_slice($ids, 0, 10))->orderBy('employee_code')->get();
        $newManager = $purpose === 'manager' && filled($get('manager_id')) ? Employee::query()->with('person')->find((int) $get('manager_id')) : null;
        $lines = $preview->map(function (Employee $e) use ($purpose, $newManager, $get) {
            if ($purpose === 'manager') {
                $now = $e->currentManager?->manager?->person?->display_name ?? 'no line manager';

                return e($e->auditLabel()).' — '.e(($get('type') ?? 'line') === 'line' ? $now : config('peopleos.people.reporting_types.'.$get('type'), 'manager')).' → '.e($newManager?->person?->display_name ?? '…');
            }
            $signIn = match ($e->user?->status) {
                null => 'no sign-in', UserStatus::Invited => 'invited', UserStatus::Active => 'active sign-in', default => 'suspended sign-in',
            };

            return e($e->auditLabel()).' — '.e($e->user?->email ?? $e->work_email ?? 'no work e-mail').' ('.$signIn.')';
        })->all();
        $what = match (true) {
            $purpose === 'manager' => 'move to '.e($newManager?->auditLabel() ?? 'the new manager').' from '.e((string) $get('effective_from')).(($get('reassign_tasks') ?? false) ? ', with their open approvals' : ''),
            $get('mode') === 'reset' => 'be sent a password reset link',
            default => 'be invited to sign in',
        };
        $more = count($ids) > count($lines) ? '<br>… and '.(count($ids) - count($lines)).' more.' : '';

        return new HtmlString('<strong>'.count($ids).' employee(s)</strong> will '.$what.' (each checked again when the run starts):<br>'.implode('<br>', $lines).$more);
    }

    private static function ccField(): TagsInput
    {
        return TagsInput::make('cc')->label('Copy (CC) a notice to')->placeholder('hr@example.com')
            ->helperText('Up to '.BulkInvitations::CC_MAX.' addresses. They get a separate notice that the e-mail was sent — never the sign-in or reset link.')
            ->nestedRecursiveRules(['email', 'max:191']);
    }

    private static function rolesField(): Select
    {
        return Select::make('roles')->label('Roles for the new sign-ins')->multiple()->preload()
            ->options(function () {
                $actor = auth()->user();
                $grants = app(RoleGrants::class);

                return Role::query()->orderBy('name')->get()->filter(fn (Role $r) => $actor !== null && $grants->canAssign($actor, $r))->pluck('name', 'id')->all();
            })
            ->default(fn () => app(BulkInvitations::class)->defaultRoleIds(auth()->user()))
            ->visible(fn () => auth()->user()?->hasPermission('user.assign_roles') ?? false)
            ->helperText('Given to the sign-ins created now (someone already invited keeps their roles). Role membership rules apply as usual.');
    }

    /** @return array<int, string> */
    private static function employeeOptions(Builder $query): array
    {
        return $query->orderBy('employee_code')->limit(50)->get()->mapWithKeys(fn (Employee $e) => [$e->getKey() => $e->auditLabel()])->all();
    }

    /** A leaver's team moves from the day after the last working day (§23.6.3 #13); otherwise today. */
    private static function defaultDate(?Employee $manager): string
    {
        $exit = $manager === null ? null : ExitCase::query()->where('employee_id', $manager->getKey())->whereIn('status', ExitCase::OPEN)->whereNotNull('last_working_day')->latest('id')->first();

        return $exit !== null ? $exit->last_working_day->copy()->addDay()->toDateString() : now()->toDateString();
    }

    private static function allowed(string $operation): bool
    {
        $user = auth()->user();

        return $user !== null && app(BulkOperations::class)->allows($user, $operation);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $inputKeys
     */
    private static function start(object $livewire, string $operationKey, array $data, array $inputKeys): void
    {
        $operation = app(BulkOperations::class)->get($operationKey);
        try {
            $ids = self::selectedIds($livewire, $data);
            $confirmed = isset($data['confirm_count']) && is_numeric($data['confirm_count']) ? (int) $data['confirm_count'] : null;
            $run = app(BulkRuns::class)->start(auth()->user(), 'employees', $operationKey, $ids, array_intersect_key($data, array_flip($inputKeys)), 'all_matching', $confirmed);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Bulk run not started')->body($e->getMessage())->send();

            return;
        }
        $run->refresh();
        $open = Action::make('open')->label('See each record')->url(BulkRunResource::getUrl('view', ['record' => $run]));
        $notification = $run->isFinished()
            ? Notification::make()->title($operation->label().': '.$run->summary())->body($run->refused + $run->failed > 0 ? 'Some employees were not done; the run lists each one with the reason.' : 'Done for every employee.')
            : Notification::make()->title($operation->label().' queued for '.$run->total.' employee(s)')->body('You will be notified with the result.');
        $notification->status($run->status === 'failed' ? 'danger' : ($run->refused + $run->failed > 0 ? 'warning' : 'success'))->actions([$open])->send();
    }
}
