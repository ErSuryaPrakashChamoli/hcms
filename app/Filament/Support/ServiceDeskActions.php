<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Knowledge\Models\Article;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\ServiceDesk\Services\CaseAssignment;
use App\Domain\ServiceDesk\Services\DomainActionExecutor;
use App\Domain\ServiceDesk\Services\DomainActions;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Domain\ServiceDesk\Services\ServiceForms;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Service desk actions for Filament (Phase 12). Every action calls the domain services, which authorise
 * again (CaseAccess, RequestLifecycle, DomainActionExecutor): visibility here is a convenience, never
 * the control.
 */
final class ServiceDeskActions
{
    public static function me(): ?Employee
    {
        return Employee::query()->where('user_id', auth()->id())->first();
    }

    /** Works the HR queue (servicedesk.agent) or reads it (servicedesk.view). */
    public static function isAgent(): bool
    {
        return app(CaseAccess::class)->isAgent(auth()->user());
    }

    /** "Ask HR": a general question in a request category (employees for themselves; agents on someone's behalf). */
    public static function askHr(): Action
    {
        return Action::make('askHr')->label('Ask HR')->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)->color('primary')
            ->visible(fn () => auth()->user()->can('create', Ticket::class) && (self::me() !== null || auth()->user()->can('servicedesk.agent')))
            ->schema([
                Select::make('employee_id')->label('For employee')->required()->searchable()->options(fn () => Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all())
                    ->default(fn () => self::me()?->id)->visible(fn () => auth()->user()->can('servicedesk.agent')),
                Select::make('ticket_category_id')->label('What is it about?')->required()->options(fn () => TicketCategory::query()->where('status', 'active')->orderBy('sort_order')->pluck('name', 'id')->all()),
                TextInput::make('subject')->required()->maxLength(255),
                Textarea::make('description')->required()->rows(5)->maxLength(4000),
                Select::make('priority')->options(config('peopleos.servicedesk.priorities'))->default('normal')->required(),
            ])
            ->action(function (array $data) {
                $employee = auth()->user()->can('servicedesk.agent') && ! empty($data['employee_id']) ? Employee::query()->findOrFail($data['employee_id']) : self::me();
                self::run(function () use ($employee, $data) {
                    $ticket = app(ServiceDesk::class)->open($employee, TicketCategory::query()->findOrFail($data['ticket_category_id']), $data['subject'], $data['description'], $data['priority'], auth()->user());

                    return 'Request '.$ticket->number.' raised';
                }, fn ($m) => $m);
            });
    }

    /**
     * "Request a service": the form is built from the pinned service version (Configuration Form fields
     * plus the domain action's fields). Mount it with ['service' => id, 'for' => 'self'|'report'|'hr'].
     */
    public static function requestService(): Action
    {
        return Action::make('requestService')->label('Request')->icon(Heroicon::OutlinedPaperAirplane)
            ->modalHeading(fn (array $arguments) => ServiceDefinition::query()->find($arguments['service'] ?? 0)?->name ?? 'Request a service')
            ->modalDescription(fn (array $arguments) => app(ServiceCatalogue::class)->versionOn((int) ($arguments['service'] ?? 0))?->description)
            ->schema(function (array $arguments) {
                $version = app(ServiceCatalogue::class)->versionOn((int) ($arguments['service'] ?? 0));
                if ($version === null) {
                    return [];
                }
                $for = $arguments['for'] ?? 'self';
                $employee = $for === 'self' ? self::me() : null;
                $fields = [];
                if ($for !== 'self') {
                    $fields[] = Select::make('employee_id')->label($for === 'report' ? 'Team member' : 'Employee')->required()->searchable()
                        ->options(fn () => self::subjects($for));
                }
                $fields[] = TextInput::make('subject')->label('Short summary')->maxLength(250)->helperText('Optional; the service name is used otherwise.');
                foreach (app(ServiceForms::class)->fields($version, $employee) as $key => $field) {
                    $fields[] = self::input("data.{$key}", $field);
                }
                $fields[] = Textarea::make('description')->label('Anything else HR should know')->rows(3)->maxLength(4000);
                if ($version->attachment_rule !== 'none') {
                    $fields[] = FileUpload::make('attachment')->label('Attachment')->disk(config('peopleos.documents.disk', 'local'))->directory('servicedesk')
                        ->maxSize(config('peopleos.documents.max_kb'))->required($version->attachment_rule === 'required');
                }
                $fields[] = TextInput::make('idempotency_key')->hidden()->default((string) Str::ulid());

                return $fields;
            })
            ->action(function (array $data, array $arguments) {
                $for = $arguments['for'] ?? 'self';
                $employee = $for === 'self' ? self::me() : Employee::query()->find($data['employee_id'] ?? 0);
                self::run(function () use ($data, $arguments, $employee, $for) {
                    if ($employee === null) {
                        throw new RuntimeException('Choose the employee.');
                    }
                    $ticket = app(ServiceRequests::class)->submit(ServiceDefinition::query()->findOrFail($arguments['service']), $employee, auth()->user(), (array) ($data['data'] ?? []), [
                        'subject' => $data['subject'] ?? null, 'description' => $data['description'] ?? null, 'attachment' => $data['attachment'] ?? null,
                        'source' => ['self' => 'web', 'report' => 'manager', 'hr' => 'hr'][$for] ?? 'web', 'idempotency_key' => $data['idempotency_key'] ?? null,
                    ]);

                    return 'Request '.$ticket->number.' submitted';
                }, fn ($m) => $m);
            });
    }

    /** @return array<int, Action> */
    public static function forTicket(): array
    {
        $access = fn () => app(CaseAccess::class);
        $works = fn (Ticket $record) => $access()->canWork(auth()->user(), $record);
        $requester = fn (Ticket $record) => $access()->isOwn(auth()->user(), $record) || (int) $record->raised_by === (int) auth()->id();
        $disk = config('peopleos.documents.disk', 'local');
        $version = fn (Ticket $record) => $record->lock_version;

        return [
            Action::make('acknowledge')->label('Acknowledge')->icon(Heroicon::OutlinedHandRaised)->color('gray')
                ->visible(fn (Ticket $record) => $record->isOpen() && $record->acknowledged_at === null && $works($record))
                ->action(fn (Ticket $record) => self::run(fn () => app(ServiceDesk::class)->acknowledge($record, auth()->user(), $version($record)), 'Acknowledged')),
            Action::make('claim')->label('Take it')->icon(Heroicon::OutlinedUserCircle)->color('primary')
                ->visible(fn (Ticket $record) => $record->isOpen() && $record->assignee_id === null && $works($record))
                ->action(fn (Ticket $record) => self::run(fn () => app(CaseAssignment::class)->claim($record, auth()->user(), $version($record)), 'Assigned to you')),
            Action::make('reply')->label('Reply')->icon(Heroicon::OutlinedChatBubbleLeft)->color('primary')
                ->visible(fn (Ticket $record) => ! in_array($record->status, ['closed', 'cancelled', 'draft'], true) && $access()->commentWritable(auth()->user(), $record) !== [])
                ->schema(fn (Ticket $record) => [
                    Textarea::make('body')->label('Message')->required()->rows(4)->maxLength(4000),
                    FileUpload::make('attachment')->disk($disk)->directory('servicedesk')->maxSize(config('peopleos.documents.max_kb'))->storeFileNamesIn('attachment_name'),
                    Radio::make('visibility')->label('Who sees it')->default('employee')->required()
                        ->options(collect(config('peopleos.servicedesk.comment_visibilities'))->only($access()->commentWritable(auth()->user(), $record))->all())
                        ->visible(fn () => count($access()->commentWritable(auth()->user(), $record)) > 1),
                ])
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(ServiceDesk::class)->comment($record, auth()->user(), $data['body'], $data['visibility'] ?? 'employee', $data['attachment'] ?? null, $data['attachment_name'] ?? null), 'Reply posted')),
            Action::make('assign')->label('Assign')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->visible(fn (Ticket $record) => $record->isOpen() && $works($record))
                ->schema(fn (Ticket $record) => [
                    Select::make('assignee_id')->label('Agent')->required()->searchable()->options(fn () => app(CaseAssignment::class)->eligibleAgents($record)->pluck('name', 'id')->all())->helperText('Only agents with this employee in their organisation scope are listed.'),
                    Select::make('team_id')->label('Team (optional)')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all()),
                    TextInput::make('reason')->maxLength(255),
                ])
                ->action(fn (Ticket $record, array $data) => self::run(function () use ($record, $data) {
                    if (filled($data['team_id'] ?? null) && (int) $data['team_id'] !== (int) $record->assigned_role_id) {
                        app(CaseAssignment::class)->assignTeam($record, Role::query()->findOrFail($data['team_id']), auth()->user(), $data['reason'] ?? null);
                    }

                    return app(CaseAssignment::class)->assign($record, User::forCurrentTenant()->findOrFail($data['assignee_id']), auth()->user(), $data['reason'] ?? null);
                }, 'Assigned')),
            Action::make('start')->label('Start work')->icon(Heroicon::OutlinedPlay)->color('gray')
                ->visible(fn (Ticket $record) => in_array($record->status, ['submitted', 'acknowledged', 'assigned', 'waiting_hr'], true) && $works($record))
                ->action(fn (Ticket $record) => self::run(fn () => app(ServiceDesk::class)->start($record, auth()->user(), $version($record)), 'In progress')),
            Action::make('waiting')->label('Wait for employee')->icon(Heroicon::OutlinedClock)->color('warning')
                ->visible(fn (Ticket $record) => in_array($record->status, ['submitted', 'acknowledged', 'assigned', 'in_progress', 'waiting_hr'], true) && $works($record))
                ->action(fn (Ticket $record) => self::run(fn () => app(ServiceDesk::class)->waitOnEmployee($record, auth()->user(), $version($record)), 'Waiting for the employee')),
            Action::make('waitingHr')->label('Wait for HR')->icon(Heroicon::OutlinedBuildingOffice)->color('gray')
                ->visible(fn (Ticket $record) => in_array($record->status, ['assigned', 'in_progress', 'waiting_employee'], true) && $works($record))
                ->schema([TextInput::make('reason')->label('Waiting on (internal)')->maxLength(255)])
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(ServiceDesk::class)->waitOnHr($record, auth()->user(), $data['reason'] ?? null, $version($record)), 'Waiting for HR')),
            Action::make('execute')->label('Apply the change')->icon(Heroicon::OutlinedBolt)->color('success')
                ->visible(fn (Ticket $record) => $record->domain_action_status === 'ready' && $works($record))
                ->requiresConfirmation()
                ->modalDescription(fn (Ticket $record) => 'Runs "'.(app(DomainActions::class)->find($record->domain_action)?->label() ?? $record->domain_action).'" in its owning domain, once. You cannot apply a change you requested or approved.')
                ->schema(fn (Ticket $record) => collect(app(DomainActions::class)->find($record->domain_action)?->executionFields(Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($record->employee_id), auth()->user()) ?? [])
                    ->map(fn (array $f, string $key) => self::input("input.{$key}", $f))->values()->all())
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(DomainActionExecutor::class)->execute($record, auth()->user(), (array) ($data['input'] ?? []), $version($record)), 'Change applied')),
            Action::make('restartApproval')->label('Restart approval')->icon(Heroicon::OutlinedArrowPath)->color('warning')
                ->visible(fn (Ticket $record) => $record->domain_action_status === 'refused' && $works($record))
                ->requiresConfirmation()
                ->action(fn (Ticket $record) => self::run(fn () => app(ServiceDesk::class)->restartApproval($record, auth()->user()), 'Approval restarted')),
            Action::make('resolve')->label('Resolve')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (Ticket $record) => $record->isOpen() && $works($record))
                ->schema(fn (Ticket $record) => [
                    Textarea::make('resolution')->required()->rows(4)->maxLength(4000)->helperText(in_array($record->domain_action_status, ['ready', 'awaiting_approval'], true) ? 'Resolving without applying withdraws the requested change.' : null),
                    Select::make('article_id')->label('Link a knowledge article')->placeholder('—')->searchable()->options(fn () => Article::query()->whereNotNull('published_version')->where('status', '!=', 'archived')->orderBy('title')->pluck('title', 'id')->all()),
                ])
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(ServiceDesk::class)->resolve($record, $data['resolution'], auth()->user(), $data['article_id'] ?? null, $version($record)), 'Resolved')),
            Action::make('close')->label(fn (Ticket $record) => $requester($record) ? 'Close & rate' : 'Close')->icon(Heroicon::OutlinedLockClosed)->color('gray')
                ->visible(fn (Ticket $record) => $record->status === 'resolved' && ($requester($record) || $works($record)))
                ->schema(fn (Ticket $record) => $requester($record) ? [
                    Radio::make('satisfaction')->label('How was the help?')->options([1 => '1 · Poor', 2 => '2', 3 => '3 · OK', 4 => '4', 5 => '5 · Excellent'])->inline(),
                    Textarea::make('comment')->maxLength(500),
                ] : [])
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(ServiceDesk::class)->close($record, auth()->user(), isset($data['satisfaction']) ? (int) $data['satisfaction'] : null, $data['comment'] ?? null), 'Closed')),
            Action::make('reopen')->label('Reopen')->icon(Heroicon::OutlinedArrowPath)->color('danger')
                ->visible(fn (Ticket $record) => in_array($record->status, ['resolved', 'closed'], true) && ($requester($record) || $works($record)))
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(ServiceDesk::class)->reopen($record, $data['reason'], auth()->user()), 'Reopened')),
            Action::make('cancel')->label('Cancel request')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (Ticket $record) => ($record->isOpen() || $record->status === 'draft') && ($requester($record) || $works($record)))
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(ServiceDesk::class)->cancel($record, auth()->user(), $data['reason'], $version($record)), 'Cancelled')),
            Action::make('grantAccess')->label('Grant access')->icon(Heroicon::OutlinedKey)->color('gray')
                ->visible(fn (Ticket $record) => $record->isRestricted() && $access()->hasExplicitAccess(auth()->user(), $record))
                ->schema([
                    Select::make('user_id')->label('Person')->required()->searchable()->options(fn () => User::forCurrentTenant()->get()->filter(fn (User $u) => $u->hasPermission('servicedesk.confidential'))->pluck('name', 'id')->all()),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(ServiceDesk::class)->grantAccess($record, User::forCurrentTenant()->findOrFail($data['user_id']), $data['reason'], auth()->user()), 'Access granted')),
        ];
    }

    /** A form input for one service field definition. */
    public static function input(string $name, array $field): mixed
    {
        $component = match ($field['type'] ?? 'text') {
            'textarea' => Textarea::make($name)->rows(3),
            'number' => TextInput::make($name)->numeric(),
            'date' => DatePicker::make($name)->native(false),
            'dropdown', 'radio' => Select::make($name)->options($field['options'] ?? []),
            'checkbox' => Toggle::make($name),
            'email' => TextInput::make($name)->email(),
            'employee' => Select::make($name)->searchable()->options(fn () => Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
            default => TextInput::make($name),
        };
        $component->label($field['label'] ?? $name)->required((bool) ($field['required'] ?? false));
        if (filled($field['help'] ?? null)) {
            $component->helperText($field['help']);
        }
        if (($field['class'] ?? 'standard') !== 'standard') {
            $component->hint('Protected');
        }

        return $component;
    }

    /** @return array<int, string> employees the user may raise a request for */
    private static function subjects(string $for): array
    {
        $user = auth()->user();
        $query = Employee::query()->with('person')->employed();
        if ($for === 'report') {
            $query->whereIn('id', app(CaseAccess::class)->teamIds($user)->all() ?: [0]);
        }

        return $query->get()->reject(fn (Employee $e) => (int) $e->user_id === (int) $user->id)->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all();
    }

    public static function refuse(string $message): void
    {
        Notification::make()->danger()->title('Not allowed')->body($message)->persistent()->send();
    }

    public static function run(callable $callback, string|callable $success): void
    {
        try {
            $result = $callback();
            Notification::make()->success()->title(is_callable($success) ? $success($result) : $success)->send();
        } catch (RuntimeException $e) {
            self::refuse($e->getMessage());
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Failed')->body($e->getMessage())->persistent()->send();
        }
    }
}
