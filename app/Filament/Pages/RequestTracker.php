<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Contracts\DescribesApprovalRoute;
use App\Domain\Experience\Contracts\RequestType;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Experience\Services\RequestFollowUps;
use App\Domain\Experience\Services\RequestPreview;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Experience\Services\RequestTimeline;
use App\Domain\Experience\Support\InboxActions;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\TaskComment;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\TaskComments;
use App\Domain\Workflow\Support\RequestWorkflows;
use App\Filament\Support\RequestChrome;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use RuntimeException;

/**
 * HROne parity RQ-2 (matrix 20 §20.1.1 #6, #8, #9, #12; §20.2.1 #40): one of the person's OWN requests, followed up —
 * whose request it is, where it stands, whom it waits with and by when, its trail (RequestTimeline), "Send reminder",
 * "Notify others" (when switched on for its type), the discussion with its approvers (the existing TaskComments thread,
 * while discussion is switched on for its kind) and "Read the policy". Opened from My requests ("Follow") and from the
 * confirmation after submitting (?request=type:id). Anyone else's request is a 404: nothing here is shown to others.
 * Withdrawing, editing and answering a send-back stay on the module's own screen ("Open").
 */
class RequestTracker extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $title = 'Request';

    protected static ?string $slug = 'request';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.experience.request-tracker';

    /** Locked: the request is fixed when the page opens (and checked there); the browser cannot point it elsewhere. */
    #[Locked, Url]
    public string $request = '';

    public string $body = '';

    /** @var array{0: RequestType, 1: Model}|null */
    private ?array $resolved = null;

    public static function canAccess(): bool
    {
        return NewRequest::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_if($this->subject() === null, 404);
    }

    /** @return array{0: RequestType, 1: Model}|null the person's own request named by ?request= */
    public function subject(): ?array
    {
        return $this->resolved ??= app(RequestTimeline::class)->resolveOwn(auth()->user(), $this->request);
    }

    public function getTitle(): string
    {
        [$type] = $this->subject() ?? [null];

        return $type !== null ? $type->label().' request' : 'Request';
    }

    /** @return array<string, mixed> what the view shows */
    public function details(): array
    {
        [$type, $record] = $this->subject() ?? abort(404);
        $user = auth()->user();
        $summary = $type->summary($record);
        $employee = $type instanceof DescribesApprovalRoute ? $type->employeeOf($record) : null;
        $followUps = app(RequestFollowUps::class);

        return [
            'type' => $type,
            'record' => $record,
            'title' => (string) $summary['title'],
            'detail' => $summary['detail'] ?? null,
            'status' => $type->status($record),
            'status_label' => $type->statusLabel($record),
            'header' => RequestChrome::renderHeader($employee),
            'trail' => app(RequestTimeline::class)->forOwn($user, $type, $record),
            'fallback' => $type instanceof DescribesApprovalRoute ? $type->builtInApprovers() : null,
            'policies' => app(RequestPreview::class)->policies($type, $user),
            'url' => $type->recordUrl($user, $record),
            'reminder' => $followUps->reminderRefusal($user, $type, $record),
            'discussion' => $this->discussion(),
        ];
    }

    /**
     * The request's conversation with its approvers (TaskComments): the thread of the workflow run deciding it, else of the
     * record the Approval Center lists — while discussion is switched on for that kind and the person is part of it.
     *
     * @return array{subject: Model, kind: string, comments: Collection<int, TaskComment>}|null
     */
    public function discussion(): ?array
    {
        [$type, $record] = $this->subject() ?? [null, null];
        if (! $type instanceof DescribesApprovalRoute || $record === null) {
            return null;
        }
        $records = $type->waitingRecords($record);
        $run = RequestWorkflows::decidingRuns(...$records)->first(fn (WorkflowInstance $r) => in_array($r->status, InstanceStatus::inFlight(), true))
            ?? RequestWorkflows::decidingRuns(...$records)->last();
        [$subject, $kind] = $run !== null ? [$run, 'workflow_task'] : [$records[0] ?? $record, $type->approvalKind($record)];
        $comments = app(TaskComments::class);
        if ($kind === null || ! InboxActions::enabled('discussion', $kind) || ! $comments->mayView(auth()->user(), $subject, $kind)) {
            return null;
        }

        return ['subject' => $subject, 'kind' => $kind, 'comments' => $comments->thread($subject)];
    }

    public function post(): void
    {
        $discussion = $this->discussion();
        if ($discussion === null) {
            return;
        }
        try {
            app(TaskComments::class)->reply(auth()->user(), $discussion['subject'], $discussion['kind'], $this->body);
            $this->body = '';
            Notification::make()->success()->title('Message posted')->send();
        } catch (InvalidArgumentException $e) {
            $this->addError('body', $e->getMessage());
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title('Not posted')->body($e->getMessage())->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $followUps = fn () => app(RequestFollowUps::class);

        return [
            Action::make('remind')->label('Send reminder')->icon(Heroicon::OutlinedBellAlert)
                ->visible(fn () => $this->isPending())
                ->disabled(fn () => $this->reminderRefusal() !== null)
                ->tooltip(fn () => $this->reminderRefusal())
                ->requiresConfirmation()
                ->modalDescription('The people your request waits with get a reminder. You can send one every '.app(RequestFollowUps::class)->minHours().' hours.')
                ->action(function () use ($followUps) {
                    [$type, $record] = $this->subject();
                    try {
                        $count = $followUps()->remind(auth()->user(), $type, $record);
                        Notification::make()->success()->title('Reminder sent')->body($count === 1 ? 'One person was reminded.' : $count.' people were reminded.')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
                    }
                }),
            Action::make('notifyOthers')->label('Notify others')->icon(Heroicon::OutlinedMegaphone)->color('gray')
                ->visible(fn () => ($s = $this->subject()) !== null && $followUps()->notifyRefusal(auth()->user(), $s[0], $s[1]) === null)
                ->modalDescription('Tell colleagues about this request. They get your message and what you asked for — never your reason or anything else about it.')
                ->schema([
                    Select::make('employees')->label('Colleagues')->multiple()->searchable()->required()->maxItems(RequestFollowUps::MAX_RECIPIENTS)
                        ->getSearchResultsUsing(fn (string $search) => $followUps()->recipientOptions(auth()->user(), $search))
                        ->getOptionLabelsUsing(fn (array $values) => $followUps()->recipientOptions(auth()->user(), null, $values)),
                    Textarea::make('message')->label('Message')->rows(3)->maxLength(RequestFollowUps::MAX_MESSAGE)
                        ->helperText('Optional. At most '.RequestFollowUps::MAX_MESSAGE.' characters.'),
                ])
                ->action(function (array $data) use ($followUps) {
                    [$type, $record] = $this->subject();
                    try {
                        $count = $followUps()->notifyOthers(auth()->user(), $type, $record, (array) ($data['employees'] ?? []), $data['message'] ?? null);
                        Notification::make()->success()->title($count === 1 ? 'One colleague informed' : $count.' colleagues informed')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
                    }
                }),
            Action::make('open')->label('Open')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->visible(fn () => ($s = $this->subject()) !== null && $s[0]->recordUrl(auth()->user(), $s[1]) !== null)
                ->url(fn () => ($s = $this->subject()) !== null ? $s[0]->recordUrl(auth()->user(), $s[1]) : null),
            Action::make('raiseAnother')->label('Raise another')->icon(Heroicon::OutlinedPlus)->color('gray')
                ->visible(fn () => $this->raiseAnotherUrl() !== null)
                ->url(fn () => $this->raiseAnotherUrl()),
        ];
    }

    public function isPending(): bool
    {
        [$type, $record] = $this->subject() ?? [null, null];

        return $type !== null && $type->status($record) === RequestStatus::Pending;
    }

    public function reminderRefusal(): ?string
    {
        [$type, $record] = $this->subject() ?? [null, null];

        return $type === null ? 'Not available.' : app(RequestFollowUps::class)->reminderRefusal(auth()->user(), $type, $record);
    }

    private function raiseAnotherUrl(): ?string
    {
        [$type] = $this->subject() ?? [null];
        $registry = app(RequestRegistry::class);
        $user = auth()->user();

        return $type !== null && $registry->isAvailable($user, $type->key()) ? $type->formUrl($user, $registry->employeeOf($user)) : null;
    }
}
