<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Services\Communications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 13: announcement attachment download. The request passes:
 * signed link → authenticated user → tenant → audience (or preparer / approver) → integrity check →
 * ATTACHMENT_DOWNLOADED audit → stream from the private disk.
 *
 * The announcement is resolved after the tenant is bound, so a foreign id fails closed (404).
 */
class AnnouncementAttachmentController extends Controller
{
    public function __invoke(Request $request, int $announcement, AuditRecorder $audit, Communications $communications): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $announcement = Announcement::query()->whereNotNull('attachment_path')->findOrFail($announcement);
        abort_unless($communications->canDownload($announcement, $request->user()), 403);

        $disk = Storage::disk(config('peopleos.documents.disk', 'local'));
        abort_unless($disk->exists($announcement->attachment_path), 404);
        abort_unless($announcement->attachment_sha256 === null || hash_equals($announcement->attachment_sha256, hash('sha256', (string) $disk->get($announcement->attachment_path))), 409, 'The attachment no longer matches its fingerprint.');

        $audit->record(AuditAction::AttachmentDownloaded, 'communication', $announcement, metadata: ['file' => $announcement->attachment_name], reason: 'attachment download');

        return $disk->download($announcement->attachment_path, $announcement->attachment_name ?? basename($announcement->attachment_path));
    }
}
