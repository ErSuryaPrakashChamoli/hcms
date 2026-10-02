<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Grievance\Models\GrievanceNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 12: grievance case-file attachment download. Each download goes through:
 * signed link → authenticated user → tenant → case access (Grievances::canAccess) → note visibility
 * (the employee only gets notes marked visible to them) → sensitive-access audit → stream from the
 * private disk. Foreign ids fail closed (404).
 */
class GrievanceAttachmentController extends Controller
{
    public function __invoke(Request $request, int $grievance, int $note, AuditRecorder $audit): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $case = Grievance::query()->findOrFail($grievance);
        Gate::authorize('view', $case);
        $entry = GrievanceNote::query()->where('grievance_id', $case->id)->whereNotNull('attachment_path')->findOrFail($note);
        if (! $entry->visible_to_employee && ! $request->user()->can('update', $case)) {
            abort(403);
        }
        $disk = config('peopleos.documents.disk', 'local');
        abort_unless(Storage::disk($disk)->exists($entry->attachment_path), 404);

        $audit->record(AuditAction::AttachmentDownloaded, 'grievance', $case, metadata: ['note_id' => $entry->id, 'file' => $entry->attachment_name, 'sensitive' => true], reason: 'attachment download');

        return Storage::disk($disk)->download($entry->attachment_path, $entry->attachment_name ?? basename($entry->attachment_path));
    }
}
