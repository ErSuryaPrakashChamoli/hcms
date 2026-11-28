<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Alumni\Models\AlumniProfile;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Services\ScheduleAssignments;
use App\Domain\Bgv\Services\Bgv;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Employment\Actions\RehireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Services\EmployeeMessages;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Experience\BulkOperations\InitiateExitOperation;
use App\Domain\Experience\Services\ContextualIntelligence;
use App\Domain\Experience\Services\Employee360Folders;
use App\Domain\Experience\Services\EmployeeProfileDetails;
use App\Domain\Experience\Services\PersonWorkspace;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Exceptions\InvalidLifecycleTransitionException;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Services\Onboarding;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workforce\Models\Position;
use App\Filament\Pages\ChangeIntelligencePage;
use App\Filament\Resources\AlumniProfiles\AlumniProfileResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\RelationManagers\CareerRelationManager;
use App\Filament\Resources\Employees\RelationManagers\CommunicationsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\ExitRelationManager;
use App\Filament\Resources\Employees\RelationManagers\GoalsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\LearningRelationManager;
use App\Filament\Resources\Employees\RelationManagers\LettersRelationManager;
use App\Filament\Resources\Employees\RelationManagers\PayslipsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\PerformanceRelationManager;
use App\Filament\Resources\Employees\RelationManagers\SkillsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\SuccessionRelationManager;
use App\Filament\Resources\Employees\RelationManagers\TalentRelationManager;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Employee360Drawers;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Filament\Support\PlacementActions;
use App\Filament\Support\SavesCustomFields;
use App\Filament\Support\ServiceDeskActions;
use App\Filament\Support\SignInActions;
use App\Filament\Support\UpcomingJoinerActions;
use App\Filament\Support\WorkforceBulkWizards;
use App\Http\Controllers\EmployeeProfilePrintController;
use App\Livewire\Compensation\CurrentSalary;
use App\Support\Numbering\NumberSeries;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

/**
 * Employee 360 (blueprint §17, §20; Experience Transformation §14). The signature header and the
 * Snapshot / Journey / 360 overview sections present existing data; header actions are the existing
 * life-event entry points, grouped as Message · Request · Action · More.
 */
class ViewEmployee extends PeopleViewRecord
{
    use SavesCustomFields;

    protected static string $resource = EmployeeResource::class;

    /**
     * UX.19 (G12): this is the one Employees page a holder of employee.self may open without the register's list access
     * (employee.view); the record check (EmployeePolicy::view, their own record only) decides, on mount and on every
     * update. The register, create, edit and navigation keep the resource-level check.
     */
    public static function needsResourceAccess(): bool
    {
        return ! (auth()->user()?->hasPermission('employee.self') ?? false);
    }

    public function mountCanAuthorizeResourceAccess(): void
    {
        if (static::needsResourceAccess()) {
            static::authorizeResourceAccess();
        }
    }

    public function hydrateCanAuthorizeResourceAccess(): void
    {
        if (static::needsResourceAccess()) {
            static::authorizeResourceAccess();
        }
    }

    public function getTitle(): string
    {
        return $this->getRecord()->person->display_name.' · '.$this->getRecord()->employee_code;
    }

    /** UX: the signature Employee 360 header (identity, context and the four action groups). */
    public function getHeader(): ?View
    {
        $record = $this->getRecord()->loadMissing(['person', 'currentPosition.designation', 'currentPosition.department', 'currentPosition.location', 'currentManager.manager.person']);

        return view('filament.employees.header', ['employee' => $record, 'actions' => $this->getCachedHeaderActions(), 'workspace' => $this->workspace, 'views' => $this->views()]);
    }

    /** UX.15: PeopleOS Intelligence for this person (contextual, sourced, gated by the AI policy). */
    #[Computed]
    public function intelligence(): ?array
    {
        return app(ContextualIntelligence::class)->forPerson(auth()->user(), $this->getRecord(), $this->workspace);
    }

    /** UX.15: the person workspace (one lifetime record, now, next, relationships, changes, summaries), once per request. */
    #[Computed]
    public function workspace(): array
    {
        return app(PersonWorkspace::class)->for(auth()->user(), $this->getRecord());
    }

    /**
     * Profile areas whose numbers are shown in full on this visit (statutory, identity, bank). Locked: only the audited
     * reveal (revealProfile, a stated purpose) adds an area, never the browser.
     *
     * @var list<string>
     */
    #[Locked]
    public array $profileRevealed = [];

    /**
     * The Profile view (owner, 2026-11-27): every detail of the employee by area (EmployeeProfileDetails, each area under
     * the rule that governs it), with what edits each area beside it for someone who may change it.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function profileAreas(): array
    {
        return array_map(fn (array $area) => $area + ['edits' => $this->profileEdits($area['key'])],
            app(EmployeeProfileDetails::class)->for(auth()->user(), $this->getRecord(), $this->profileRevealed));
    }

    /** Opening the Profile view shows bank and statutory details (masked): recorded like opening the Bank tab. */
    #[Renderless]
    public function profileOpened(): void
    {
        if (auth()->user()?->can('viewSensitive', $this->getRecord())) {
            app(SensitiveAccessAuditor::class)->recordView($this->getRecord(), 'profile');
        }
    }

    /** Masks an area's numbers again (nothing to record). */
    public function hideProfileNumbers(string $area): void
    {
        $this->profileRevealed = array_values(array_diff($this->profileRevealed, [$area]));
        unset($this->profileAreas);
    }

    /** Shows one area's numbers in full on this visit, after a stated purpose that the audit trail keeps (as the Bank tab's Reveal). */
    public function revealProfileAction(): Action
    {
        $what = fn (array $arguments): string => EmployeeProfileDetails::REVEALABLE[$arguments['area'] ?? ''] ?? 'numbers';

        return Action::make('revealProfile')
            ->label('Show numbers')->icon(Heroicon::OutlinedEye)
            ->authorize(fn () => auth()->user()->can('viewSensitive', $this->getRecord()))
            ->modalHeading(fn (array $arguments) => 'Show '.$what($arguments))
            ->modalDescription('The full numbers stay on this page until you leave it or hide them. Your name, the time and the purpose are recorded in the audit trail.')
            ->modalSubmitActionLabel('Show numbers')
            ->schema([Textarea::make('purpose')->label('Purpose')->required()->rows(2)->maxLength(500)->helperText('For example: checking the UAN for a PF transfer.')])
            ->action(function (array $data, array $arguments) {
                $area = (string) ($arguments['area'] ?? '');
                if (! array_key_exists($area, EmployeeProfileDetails::REVEALABLE)) {
                    return;
                }
                app(SensitiveAccessAuditor::class)->recordView($this->getRecord(), 'profile:'.$area, (string) $data['purpose']);
                $this->profileRevealed = array_values(array_unique([...$this->profileRevealed, $area]));
                unset($this->profileAreas);
            });
    }

    /**
     * What changes each Profile area, for someone who may change it: the step of the same name on the Edit page (owner,
     * 2026-11-27: one place to change each detail); position and reporting lines through Transfer / promote and Change
     * manager, their only place.
     *
     * @return list<array{label: string, url?: string, action?: string}>
     */
    private function profileEdits(string $area): array
    {
        $record = $this->getRecord();
        $user = auth()->user();
        $resource = static::getResource();
        $canEdit = $resource::canEdit($record);
        $edit = fn (string $label, string $step) => $canEdit ? ['label' => $label, 'url' => $resource::getUrl('edit', ['record' => $record, 'step' => $step])] : null;
        // A list step shows only to someone who may see one of its lists.
        $lists = fn (string $label, string $step, string $key) => collect(EmployeeResource::STEP_LISTS[$key])->contains(fn (string $m) => $m::canViewForRecord($record, EditEmployee::class)) ? $edit($label, $step) : null;
        $position = $user?->can('assignPosition', $record) ?? false;

        return array_values(array_filter(match ($area) {
            'personal' => [$edit('Edit personal details', 'personal')],
            'contact' => [$edit('Edit contact & addresses', 'contact-addresses')],
            // Position and reporting lines change only through Transfer / promote and Change manager (PlacementActions).
            'employment' => [$edit('Edit employment details', 'employment'),
                $position ? ['label' => 'Transfer / promote', 'action' => 'assignPosition'] : null,
                $position ? ['label' => 'Change manager', 'action' => 'changeManager'] : null],
            'statutory' => [($user?->can('updateSensitive', $record) ?? false) ? $edit('Edit statutory details', 'statutory') : null],
            'identity' => [$lists('Edit government IDs', 'government-ids', 'identity')],
            'bank' => [$lists('Edit bank accounts', 'bank-payment', 'bank')],
            'family' => [$lists('Edit family & nominees', 'family-nominees', 'family')],
            'professional' => [$lists('Edit education & experience', 'education-experience', 'professional')],
            'insurance' => [$lists('Edit health insurance', 'health-insurance', 'insurance')],
            'other' => [$edit('Edit other details', 'other-details')],
            default => [],
        }));
    }

    /**
     * The 360 views that have something for this viewer, in order (the section navigation). Now, Profile, Journey,
     * Work, Growth, Rewards and Documents always show (each says when nothing in it is visible); Time and Activity only
     * when one of their lists is.
     *
     * @return array<string, string> view key => label
     */
    public function views(): array
    {
        $views = ['now' => 'Now', 'profile' => 'Profile', 'journey' => 'Journey', 'work' => 'Work', 'time' => 'Time', 'growth' => 'Growth', 'rewards' => 'Rewards', 'documents' => 'Documents', 'activity' => 'Activity'];

        return array_filter($views, fn (string $label, string $view) => ! in_array($view, ['time', 'activity'], true) || $this->visibleLists($view) !== [], ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The lists of one 360 view this viewer may see.
     *
     * @return list<class-string>
     */
    public function visibleLists(string $view): array
    {
        return array_values(array_filter(EmployeeResource::VIEW_LISTS[$view] ?? [], fn (string $manager) => $manager::canViewForRecord($this->getRecord(), static::class)));
    }

    /**
     * Owner, 2026-11-27: every detail in one place. Under the 360 views, each view shows its own lists (the Work view the
     * reporting lines, position history, onboarding, exit and workflows; Growth its reviews, goals and learning; …), and
     * nothing else: no record tabs repeating them. Person data is read in Profile. Each list keeps its own access rule,
     * loads when its view is first opened, and stays read-only here except for its own actions (upload, apply, propose …).
     */
    public function content(Schema $schema): Schema
    {
        $record = $this->getRecord();
        $lists = collect(EmployeeResource::VIEW_LISTS)->map(fn (array $managers, string $view) => Group::make(
            collect($this->visibleLists($view))->map(fn (string $manager) => Livewire::make($manager, ['ownerRecord' => $record, 'pageClass' => static::class, ...$manager::getDefaultProperties()])
                ->key('view-list-'.class_basename($manager))
                ->id('list-'.class_basename($manager))
                ->extraAttributes(['class' => 'pos-360-list'])
            )->all())
            ->extraAttributes(['class' => 'pos-360-lists', 'data-pos360-of' => $view, 'x-data' => '', 'x-cloak' => '',
                'x-show' => "\$store.pos360 ? \$store.pos360.view === '{$view}' : true"])
            ->visible($this->visibleLists($view) !== []))->values()->all();

        return $schema->components([$this->getInfolistContentComponent(), ...$lists]);
    }

    /**
     * Where a list of this employee is (the cards of the 360 and its drawers link there), or null when the person cannot
     * see it: a list of the 360 opens its view on this page (#list-<class>, scrolled to); a list of person data opens its
     * step on the Edit page for someone who may edit the employee, else the Profile view where it is read.
     *
     * @param  class-string  $manager
     */
    public function listUrl(string $manager): ?string
    {
        $record = $this->getRecord();
        $resource = static::getResource();
        foreach (EmployeeResource::VIEW_LISTS as $view => $managers) {
            if (in_array($manager, $managers, true)) {
                return $manager::canViewForRecord($record, static::class) ? $resource::getUrl('view', ['record' => $record]).'#list-'.class_basename($manager) : null;
            }
        }
        foreach (EmployeeResource::STEP_LISTS as $key => $managers) {
            if (in_array($manager, $managers, true)) {
                if ($resource::canEdit($record) && $manager::canViewForRecord($record, EditEmployee::class)) {
                    return $resource::getUrl('edit', ['record' => $record, 'step' => self::STEP_OF_LIST[$key]]).'#list-'.class_basename($manager);
                }

                return $manager::canViewForRecord($record, static::class) ? $resource::getUrl('view', ['record' => $record]).'#profile' : null;
            }
        }

        return null;
    }

    /** EmployeeResource::STEP_LISTS key => the Edit page step that holds it. */
    private const STEP_OF_LIST = ['contact' => 'contact-addresses', 'identity' => 'government-ids', 'bank' => 'bank-payment',
        'family' => 'family-nominees', 'professional' => 'education-experience', 'insurance' => 'health-insurance'];

    /**
     * UX §14: Message · Request · Action · More. Every action keeps its name, authorisation and domain
     * service; only the grouping changed (Life events became "Action", edit / history / statutory "More").
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('message')->label('Message')->icon(Heroicon::OutlinedEnvelope)->color('gray')
                ->visible(fn () => filled($this->getRecord()->work_email) && (int) $this->getRecord()->user_id !== (int) auth()->id())
                ->url(fn () => 'mailto:'.$this->getRecord()->work_email),
            ActionGroup::make([
                $this->raiseRequestAction(),
                $this->generateLetterAction(),
                $this->sendEmailAction(),
            ])->label('Request')->icon(Heroicon::OutlinedPaperAirplane)->button()->color('gray'),
            ActionGroup::make([
                // One place for position and reporting changes (PlacementActions; also in the Edit page's Employment step).
                PlacementActions::transfer(),
                PlacementActions::changeManager(),
                // HROne parity WO-16: move this person's team (reporting or functional) to a new manager, in bulk.
                WorkforceBulkWizards::changeManager('changeTeamManager', fn () => $this->getRecord()),
                $this->lifecycleAction(),
                $this->markJoinedAction(),
                // HROne parity WO-4: an upcoming joiner's joining date, not joining, all documents, the profile-completion mail.
                ...UpcomingJoinerActions::all(),
                $this->startOnboardingAction(),
                $this->initiateBgvAction(),
                $this->assignScheduleAction(),
                $this->startWorkflowAction(),
                $this->initiateExitAction(),
                $this->rehireAction(),
            ])->label('Action')->icon(Heroicon::OutlinedBolt)->button(),
            // Sign-in: invite (a temporary password e-mailed), e-mail a new one or generate one to hand over (SignInActions).
            ActionGroup::make([
                SignInActions::invite(fn () => $this->getRecord()),
                ...SignInActions::forUser(fn () => $this->getRecord()->user),
                SignInActions::openUser(fn () => $this->getRecord()->user),
            ])->label('Sign-in')->icon(Heroicon::OutlinedKey)->button()->color('gray'),
            ActionGroup::make([
                EditAction::make()->label('Edit profile'),
                // Phase 14: every audited change to this employee's records (employee, person and linked rows), in Change Intelligence.
                Action::make('changes')->label('All changes')->icon('heroicon-m-magnifying-glass-circle')
                    ->visible(fn () => auth()->user()?->hasPermission('audit.view') ?? false)
                    ->url(fn () => ChangeIntelligencePage::getUrl(['employee' => $this->getRecord()->getKey()])),
                $this->printProfileAction(),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisHorizontal)->button()->color('gray'),
        ];
    }

    public function temporaryPasswordShownAction(): Action
    {
        return SignInActions::shown();
    }

    /** The 360 Documents card: the person's documents by category and document type, with an upload on each (Employee360Drawers). */
    public function documents360Action(): Action
    {
        return Employee360Drawers::documents(fn () => $this->getRecord(), fn () => $this->listUrl(DocumentsRelationManager::class));
    }

    /** The 360 Letters card: the person's letters by letter type, with generate and upload on each (Employee360Drawers). */
    public function letters360Action(): Action
    {
        return Employee360Drawers::letters(fn () => $this->getRecord(), fn () => $this->listUrl(LettersRelationManager::class));
    }

    /**
     * What each 360 overview card opens (owner, 2026-11-27: the cards must be clickable): Documents and Letters open their
     * drawers; a card whose detail is a list opens that list (under its view); Alumni opens the alumni profile. A card the
     * viewer cannot follow anywhere stays as it is.
     *
     * @return array<string, array{action?: string, url?: string, cta: string}> card key => how it opens
     */
    #[Computed]
    public function cardLinks(): array
    {
        $user = auth()->user();
        $record = $this->getRecord();
        $folders = app(Employee360Folders::class);
        $links = [];
        if ($folders->maySeeDocuments($user, $record)) {
            $links['documents'] = ['action' => 'documents360', 'cta' => 'Open by document type'];
        }
        if ($folders->maySeeLetters($user, $record)) {
            $links['letters'] = ['action' => 'letters360', 'cta' => 'Open by letter type'];
        }
        foreach (self::CARD_RECORDS as $key => $manager) {
            if ($url = $this->listUrl($manager)) {
                $links[$key] = ['url' => $url, 'cta' => $key === 'compensation' ? 'Open the salary and its breakup' : 'Open the full list'];
            }
        }
        $alumni = AlumniProfile::query()->where('employee_id', $record->getKey())->first();
        if ($alumni !== null && AlumniProfileResource::canView($alumni)) {
            $links['alumni'] = ['url' => AlumniProfileResource::getUrl('view', ['record' => $alumni]), 'cta' => 'Open alumni profile'];
        }

        return $links;
    }

    /** 360 overview card key => the list that holds its detail. */
    private const CARD_RECORDS = [
        'performance' => PerformanceRelationManager::class, 'goals' => GoalsRelationManager::class, 'learning' => LearningRelationManager::class,
        'skills' => SkillsRelationManager::class, 'career' => CareerRelationManager::class, 'talent' => TalentRelationManager::class,
        'succession' => SuccessionRelationManager::class, 'compensation' => CurrentSalary::class, 'payroll' => PayslipsRelationManager::class,
        'communication' => CommunicationsRelationManager::class, 'exit' => ExitRelationManager::class,
    ];

    /** HROne parity WO-2 ("Send email"): write to the employee through EmployeeMessages (audited without the body). */
    private function sendEmailAction(): Action
    {
        return Action::make('sendEmail')->label('Send e-mail')->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn () => auth()->user()?->hasPermission('employee.message') ?? false)
            ->modalDescription('Sent from PeopleOS to the employee\'s e-mail and listed on the delivery log. The audit trail keeps the subject, not the message.')
            ->fillForm(['to' => ['work']])
            ->schema([
                TextInput::make('subject')->required()->maxLength(200),
                Textarea::make('body')->label('Message')->required()->rows(6)->maxLength(10000),
                CheckboxList::make('to')->label('Send to')->required()
                    ->options(fn () => auth()->user()?->hasPermission('employee.sensitive.view') ? EmployeeMessages::RECIPIENTS : ['work' => EmployeeMessages::RECIPIENTS['work']]),
                Toggle::make('copy_to_me')->label('Send me a copy'),
            ])
            ->action(fn (Employee $record, array $data) => ServiceDeskActions::run(fn () => app(EmployeeMessages::class)->email($record, auth()->user(), (string) $data['subject'], (string) $data['body'], (array) ($data['to'] ?? ['work']), (bool) ($data['copy_to_me'] ?? false)),
                fn (int $sent) => $sent === 1 ? 'E-mail queued' : "{$sent} e-mails queued"));
    }

    /**
     * HROne parity WO-2 ("Reactivate employee" / rehire): an exited employee or an alumni comes back as the same employee
     * (code, person and history kept) through RehireEmployeeAction — the lifecycle moves only through LifecycleEngine.
     */
    private function rehireAction(): Action
    {
        return Action::make('rehire')->label('Rehire / reactivate')->icon(Heroicon::OutlinedArrowUturnLeft)
            ->authorize(fn () => auth()->user()->can('transition', $this->getRecord()) && auth()->user()->can('assignPosition', $this->getRecord()))
            ->visible(fn (Employee $record) => in_array($record->lifecycle_state, [LifecycleState::Exited, LifecycleState::Alumni], true))
            ->modalDescription('The same employee returns as active from the rejoining date: employee code, person, documents and history are kept. Blank position fields stay as they were. Their login stays as it is (invite them again from Users if needed).')
            ->fillForm(fn () => ['rejoin_date' => now()->toDateString()])
            ->schema([
                DatePicker::make('rejoin_date')->label('Rejoining date')->native(false)->required(),
                Grid::make(3)->schema(CreateEmployee::positionFields()),
                Select::make('manager_id')->label('Line manager')->options(fn (Employee $record) => CreateEmployee::managerOptions($record->id))->searchable()->placeholder('Unchanged'),
                AuditReasonField::make()->required(),
            ])
            ->action(function (Employee $record, array $data) {
                $reason = AuditReasonField::extract($data);
                try {
                    $rehired = app(RehireEmployeeAction::class)->handle($record, array_intersect_key($data, EmployeePosition::DIMENSIONS), $data['rejoin_date'], filled($data['manager_id'] ?? null) ? (int) $data['manager_id'] : null, $reason);
                    Notification::make()->success()->title('Employee is active again')->send();
                    PlacementActions::requirementWarnings($rehired->requirementWarnings);
                } catch (InvalidArgumentException|InvalidLifecycleTransitionException|\RuntimeException $e) {
                    Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                }
            });
    }

    /** HROne parity WO-1 (§23.2.2 #5 "Initiate separation" on the card): the same inputs and Exits::initiate as the bulk action. */
    private function initiateExitAction(): Action
    {
        $operation = app(InitiateExitOperation::class);

        return Action::make('initiateExit')->label('Initiate exit')->icon(Heroicon::OutlinedArrowRightStartOnRectangle)->color('danger')
            ->authorize(fn () => auth()->user()->can('exit.manage'))
            ->visible(fn (Employee $record) => $record->lifecycle_state->isEmployed() && $record->lifecycle_state !== LifecycleState::NoticePeriod
                && $record->exitCases()->whereIn('status', ExitCase::OPEN)->doesntExist())
            ->modalDescription('Opens an exit case for this employee (notice, clearance stages, exit interviews and letters as the exit policy says).')
            ->schema(fn () => $operation->schema())
            ->action(fn (Employee $record, array $data) => ServiceDeskActions::run(fn () => $operation->handle($record, $operation->prepare($data, auth()->user()), auth()->user()), fn (string $line) => $line));
    }

    /** HROne parity WO-2 ("Download profile data"): the printable profile (sensitive sections only with their permission; audited). */
    private function printProfileAction(): Action
    {
        return Action::make('printProfile')->label('Print profile')->icon(Heroicon::OutlinedPrinter)
            ->visible(fn (Employee $record) => EmployeeProfilePrintController::allows(auth()->user(), $record))
            ->url(fn (Employee $record) => EmployeeProfilePrintController::url($record), shouldOpenInNewTab: true);
    }

    /** An HR agent raises a request on this person's behalf (the existing Ask HR action, pre-filled). */
    private function raiseRequestAction(): Action
    {
        return ServiceDeskActions::askHr()->name('raiseRequest')->label('Raise an HR request')
            ->visible(fn () => auth()->user()->can('servicedesk.agent') && auth()->user()->can('create', Ticket::class))
            ->fillForm(fn () => ['employee_id' => $this->getRecord()->getKey(), 'priority' => 'normal']);
    }

    /** Generate a letter for this person through the Letters service (same rules as the Letters screen). */
    private function generateLetterAction(): Action
    {
        return Action::make('generateLetter')->label('Generate a letter')->icon(Heroicon::OutlinedDocumentPlus)
            ->visible(fn () => auth()->user()->can('letter.issue'))
            ->schema([
                Select::make('template_id')->label('Template')->required()
                    ->options(fn () => LetterTemplate::query()->where('status', 'active')
                        ->when(NumberSeries::companyOf($this->getRecord()), fn ($q, $company) => app(EntityApplicability::class)->constrain($q, $company))
                        ->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->action(fn (array $data) => ServiceDeskActions::run(fn () => app(Letters::class)->generate(LetterTemplate::query()->findOrFail($data['template_id']), $this->getRecord(), [], auth()->user()),
                fn ($l) => "Letter {$l->number} ".($l->status === 'approved' ? 'ready to issue' : 'sent for approval')));
    }

    private function lifecycleAction(): Action
    {
        return Action::make('lifecycle')
            ->label('Change lifecycle state')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize(fn () => auth()->user()->can('transition', $this->getRecord()))
            ->fillForm(fn () => ['effective_date' => now()->toDateString()])
            ->schema([
                Select::make('to_state')
                    ->label('New state')
                    // HROne parity WO-4: "not joined" has its own action (a reason; it closes the onboarding), never this list.
                    ->options(fn (Employee $record) => collect($record->lifecycle_state->allowedNext())->reject(fn (LifecycleState $s) => $s === LifecycleState::NotJoined)->mapWithKeys(fn (LifecycleState $s) => [$s->value => $s->getLabel()])->all())
                    ->required(),
                DatePicker::make('effective_date')->native(false)->required(),
                AuditReasonField::make()->required(),
            ])
            ->action(function (Employee $record, array $data) {
                $reason = AuditReasonField::extract($data);

                try {
                    app(LifecycleEngine::class)->transition($record, LifecycleState::from($data['to_state']), $data['effective_date'], $reason);
                    Notification::make()->success()->title('Lifecycle updated')->send();
                } catch (InvalidLifecycleTransitionException $e) {
                    Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                }
            });
    }

    private function markJoinedAction(): Action
    {
        return Action::make('markJoined')
            ->label('Mark as joined')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->authorize(fn () => auth()->user()->can('transition', $this->getRecord()))
            ->visible(fn (Employee $record) => in_array($record->lifecycle_state, [LifecycleState::PreEmployee, LifecycleState::Preboarding, LifecycleState::Onboarding], true))
            ->fillForm(fn (Employee $record) => ['joining_date' => ($record->expected_joining_date ?? now())->toDateString()])
            ->schema([
                DatePicker::make('joining_date')->native(false)->required(),
                AuditReasonField::make(),
            ])
            ->action(function (Employee $record, array $data) {
                $reason = AuditReasonField::extract($data);

                try {
                    $engine = app(LifecycleEngine::class);
                    $record->withAuditReason($reason)->update(['joining_date' => $data['joining_date']]);
                    $engine->transition($record, LifecycleState::Joined, $data['joining_date'], $reason);
                    $engine->transition($record, LifecycleState::Probation, $data['joining_date'], $reason);
                    Notification::make()->success()->title('Joined')->body('Now in probation.')->send();
                } catch (InvalidLifecycleTransitionException $e) {
                    Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                }
            });
    }

    private function startOnboardingAction(): Action
    {
        return Action::make('startOnboarding')
            ->label('Start onboarding')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->authorize(fn () => auth()->user()->can('onboarding.manage'))
            ->visible(fn (Employee $record) => $record->onboardingPlan()->where('status', 'in_progress')->doesntExist())
            ->schema([
                Select::make('template_id')->label('Template')
                    ->options(fn () => OnboardingTemplate::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('Best match by rules')
                    ->helperText('Leave empty to pick the highest-priority template whose conditions match.'),
                AuditReasonField::make(),
            ])
            ->action(function (Employee $record, array $data) {
                try {
                    $template = isset($data['template_id']) ? OnboardingTemplate::query()->find($data['template_id']) : null;
                    $plan = app(Onboarding::class)->start($record, $template, $data[AuditReasonField::NAME] ?? null);
                    Notification::make()->success()->title('Onboarding started')->body($plan->tasks()->count().' tasks created.')->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->danger()->title('Could not start')->body($e->getMessage())->send();
                }
            });
    }

    private function initiateBgvAction(): Action
    {
        return Action::make('initiateBgv')
            ->label('Initiate background check')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->authorize(fn () => auth()->user()->can('bgv.manage'))
            ->visible(fn (Employee $record) => $record->bgvCases()->whereIn('status', ['initiated', 'in_progress'])->doesntExist())
            ->fillForm(['checks' => config('peopleos.bgv.default_checks'), 'provider' => 'manual'])
            ->schema([
                Select::make('provider')->options(collect(config('peopleos.bgv.providers'))->map(fn ($p) => $p['label'])->all())->required(),
                CheckboxList::make('checks')->options(config('peopleos.bgv.check_types'))->columns(2)->required(),
                Toggle::make('consent')->label('The employee has given written consent')->required()->accepted(),
                Select::make('consent_document_id')->label('Consent document')->options(fn (Employee $record) => $record->documents()->pluck('title', 'id')->all())->placeholder('Optional'),
                Textarea::make('notes')->maxLength(1000),
            ])
            ->action(function (Employee $record, array $data) {
                try {
                    $consentDoc = isset($data['consent_document_id']) ? $record->documents()->find($data['consent_document_id']) : null;
                    $case = app(Bgv::class)->initiate($record, $data['checks'], $data['provider'], (bool) $data['consent'], $consentDoc, $data['notes'] ?? null);
                    Notification::make()->success()->title("BGV case #{$case->id} initiated")->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->danger()->title('Could not initiate')->body($e->getMessage())->send();
                }
            });
    }

    private function assignScheduleAction(): Action
    {
        return Action::make('assignSchedule')
            ->label('Assign work schedule')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->authorize(fn () => auth()->user()->can('attendance.manage'))
            ->fillForm(['effective_from' => now()->toDateString()])
            ->schema([
                Select::make('work_schedule_id')->label('Schedule')->options(fn () => WorkSchedule::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())->required(),
                DatePicker::make('effective_from')->native(false)->required(),
                AuditReasonField::make(),
            ])
            ->action(function (Employee $record, array $data) {
                $reason = AuditReasonField::extract($data);
                // Data import DI-2: the same write path as the Employee settings upload.
                app(ScheduleAssignments::class)->assign($record, WorkSchedule::query()->findOrFail($data['work_schedule_id']), $data['effective_from'], $reason, auth()->user());

                Notification::make()->success()->title('Schedule assigned')->send();
            });
    }

    private function startWorkflowAction(): Action
    {
        return Action::make('startWorkflow')
            ->label('Start workflow')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->authorize(fn () => auth()->user()->can('workflow.run'))
            ->schema([
                Select::make('workflow_id')
                    ->label('Workflow')
                    ->options(fn () => Workflow::query()->where('trigger_event', 'manual')->where('status', 'active')->whereHas('published')
                        ->where(fn ($q) => $q->whereNull('subject_type')->orWhere('subject_type', Employee::class))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
                AuditReasonField::make(),
            ])
            ->action(function (Employee $record, array $data) {
                try {
                    $instance = app(WorkflowEngine::class)->start(Workflow::query()->findOrFail($data['workflow_id']), $record, ['trigger' => 'manual', 'reason' => $data[AuditReasonField::NAME] ?? null]);
                    Notification::make()->success()->title("Run #{$instance->id} started")->send();

                    return redirect()->to(WorkflowInstanceResource::getUrl('view', ['record' => $instance]));
                } catch (WorkflowException $e) {
                    Notification::make()->danger()->title('Could not start')->body($e->getMessage())->send();
                }
            });
    }
}
