<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Support\InboxActions;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workflow\Models\RequestReturn;
use App\Domain\Workflow\Models\TaskComment;
use App\Domain\Workflow\Services\RequestReturns;
use App\Domain\Workflow\Services\TaskComments;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use RuntimeException;

/**
 * HROne setup parity P3 (Inbox → Discussion / Send back), for the requester's side: one request's conversation with its
 * approvers, reached from a notification (?comment=) or from "Sent back to me" (?return=). Shows what the approver asked
 * for when the request was sent back, the messages, a reply box (while discussion is switched on for its type) and, for
 * the person it was sent back to, Resubmit. Only the requester, the approvers and holders of task.view_all open it.
 */
class RequestDiscussion extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $title = 'Request discussion';

    protected static ?string $slug = 'request-discussion';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.request-discussion';

    /** Locked: the thread is fixed when the page opens (and checked there); the browser cannot point it elsewhere. */
    #[Locked, Url]
    public ?int $comment = null;

    #[Locked, Url(as: 'return')]
    public ?int $returnId = null;

    public string $body = '';

    public string $response = '';

    public static function canAccess(): bool
    {
        return auth()->user() !== null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        [$subject, $kind] = $this->thread();
        abort_if($subject === null, 404);
        abort_unless(app(TaskComments::class)->mayView(auth()->user(), $subject, $kind), 403);
    }

    /** @return array{0: ?Model, 1: string, 2: ?RequestReturn} the request, its type and the send-back the page was opened from */
    public function thread(): array
    {
        $return = $this->returnId ? RequestReturn::query()->find($this->returnId) : null;
        $anchor = $return === null && $this->comment ? TaskComment::query()->find($this->comment) : null;
        $source = $return ?? $anchor;
        if ($source === null) {
            return [null, '', null];
        }
        $type = $return ? $return->subject_type : $anchor->commentable_type;
        $id = $return ? $return->subject_id : $anchor->commentable_id;
        $class = Relation::getMorphedModel($type) ?? $type;
        $subject = class_exists($class) ? $class::query()->withoutGlobalScope(AccessScope::class)->find($id) : null;

        return [$subject, (string) $source->kind, $return ?? $this->openReturn($subject)];
    }

    /** @return array<string, mixed> what the view shows */
    public function details(): array
    {
        [$subject, $kind, $return] = $this->thread();
        $user = auth()->user();
        abort_unless($subject !== null && app(TaskComments::class)->mayView($user, $subject, $kind), 403);

        return [
            'kind' => ApprovalCenter::kinds()[$kind] ?? $kind,
            'label' => $subject !== null && method_exists($subject, 'auditLabel') ? $subject->auditLabel() : null,
            'return' => $return,
            'comments' => $subject !== null ? app(TaskComments::class)->thread($subject) : collect(),
            'mayReply' => $subject !== null && InboxActions::enabled('discussion', $kind),
            'mayResubmit' => $return !== null && $return->isOpen() && (int) $return->requester_id === (int) $user->id
                && app(RequestReturns::class)->stillWaiting($return, $return->subject()->withoutGlobalScope(AccessScope::class)->first()),
        ];
    }

    public function post(): void
    {
        [$subject, $kind] = $this->thread();
        if ($subject === null) {
            return;
        }
        try {
            app(TaskComments::class)->reply(auth()->user(), $subject, $kind, $this->body);
            $this->body = '';
            Notification::make()->success()->title('Message posted')->send();
        } catch (InvalidArgumentException $e) {
            $this->addError('body', $e->getMessage());
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title('Not posted')->body($e->getMessage())->send();
        }
    }

    public function resubmit(): void
    {
        [, , $return] = $this->thread();
        if ($return === null) {
            return;
        }
        try {
            app(RequestReturns::class)->resubmit($return, auth()->user(), $this->response);
            $this->response = '';
            Notification::make()->success()->title('Resubmitted')->body('It is back with the approvers.')->send();
        } catch (AuthorizationException|RuntimeException $e) {
            Notification::make()->danger()->title('Not resubmitted')->body($e->getMessage())->send();
        }
    }

    private function openReturn(?Model $subject): ?RequestReturn
    {
        if ($subject === null) {
            return null;
        }

        return RequestReturn::query()->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->where('status', 'open')->latest('id')->first();
    }
}
