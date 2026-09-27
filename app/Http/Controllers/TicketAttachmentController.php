<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ticket attachment download (Phase 0.2): signed link -> authenticated user -> tenant -> ticket
 * authorisation -> comment visibility -> access audit -> stream from the private disk. The
 * ticket and comment are resolved after the tenant is bound so foreign ids fail closed (404).
 */
class TicketAttachmentController extends Controller
{
    public function __invoke(Request $request, int $ticket, int $comment, AuditRecorder $audit): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $ticket = Ticket::query()->findOrFail($ticket);
        Gate::authorize('view', $ticket);

        $comment = TicketComment::query()->where('ticket_id', $ticket->id)->whereNotNull('attachment_path')->findOrFail($comment);

        if ($comment->is_internal && ! ($request->user()->can('servicedesk.view') || $ticket->assignee_id === $request->user()->id)) {
            abort(403);
        }

        $disk = config('peopleos.documents.disk', 'local');
        abort_unless(Storage::disk($disk)->exists($comment->attachment_path), 404);

        $audit->record(AuditAction::Download, 'servicedesk', $comment, metadata: ['ticket_id' => $ticket->id, 'file' => $comment->attachment_name], reason: 'attachment download');

        return Storage::disk($disk)->download($comment->attachment_path, $comment->attachment_name ?? basename($comment->attachment_path));
    }
}
