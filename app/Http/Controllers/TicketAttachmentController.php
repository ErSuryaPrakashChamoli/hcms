<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketComment;
use App\Domain\ServiceDesk\Services\CaseAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ticket attachment download (Phase 0.2, Phase 12). Each download goes through:
 * signed link → authenticated user → tenant → case authorisation (CaseAccess) → comment visibility
 * (employee / internal / restricted) → ATTACHMENT_DOWNLOADED audit → stream from the private disk.
 * The ticket and comment are resolved after the tenant is bound, so foreign ids fail closed (404).
 */
class TicketAttachmentController extends Controller
{
    public function __invoke(Request $request, int $ticket, int $comment, AuditRecorder $audit, CaseAccess $access): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $ticket = Ticket::query()->findOrFail($ticket);
        Gate::authorize('view', $ticket);

        $comment = TicketComment::query()->where('ticket_id', $ticket->id)->whereNotNull('attachment_path')->findOrFail($comment);
        abort_unless(in_array($comment->visibility, $access->commentVisibilities($request->user(), $ticket), true), 403);

        $disk = config('peopleos.documents.disk', 'local');
        abort_unless(Storage::disk($disk)->exists($comment->attachment_path), 404);

        $audit->record(AuditAction::AttachmentDownloaded, 'servicedesk', $ticket, metadata: ['comment_id' => $comment->id, 'file' => $comment->attachment_name, 'visibility' => $comment->visibility], reason: 'attachment download');

        return Storage::disk($disk)->download($comment->attachment_path, $comment->attachment_name ?? basename($comment->attachment_path));
    }
}
