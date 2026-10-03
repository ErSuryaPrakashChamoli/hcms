<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketComment;
use App\Support\Storage\FileSafety;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Phase 12: case attachments on the private document disk (the Document domain's storage; no second
 * store, no public URL). Each file:
 * - is kept under `tenants/{tenant}/servicedesk/{ticket}/{ulid}.{ext}`;
 * - is checked against the document type and size limits;
 * - is fingerprinted (SHA-256);
 * - is reached only through a short-lived signed link, whose route re-authorises the case and the
 *   comment visibility and audits the download.
 */
final class CaseAttachments
{
    /** @return array{attachment_path: string, attachment_name: string, attachment_size: int, attachment_mime: ?string, attachment_sha256: string} */
    public function store(Ticket $ticket, UploadedFile|string $file, ?string $name = null): array
    {
        $disk = Storage::disk(config('peopleos.documents.disk', 'local'));
        $directory = "tenants/{$ticket->tenant_id}/servicedesk/{$ticket->id}";
        if ($file instanceof UploadedFile) {
            $original = $name ?? $file->getClientOriginalName();
            $this->assertAllowed($original, (int) $file->getSize(), FileSafety::sniffFile((string) $file->getRealPath()) ?? $file->getMimeType());
            $path = $file->storeAs($directory, Str::ulid().'.'.strtolower($file->getClientOriginalExtension()), config('peopleos.documents.disk', 'local'));
        } else {
            // A file a Filament upload already placed on the private disk (its temporary upload directory).
            $source = ltrim(str_replace(['\\', '..'], ['/', ''], $file), '/');
            if (! Str::startsWith($source, ['servicedesk/', $directory.'/']) || ! $disk->exists($source)) {
                throw new ServiceDeskRuleViolation('The attachment upload was not found.');
            }
            $original = $name ?? basename($source);
            $this->assertAllowed($original, (int) $disk->size($source), FileSafety::sniff((string) $disk->get($source)));
            $path = $directory.'/'.Str::ulid().'.'.strtolower(pathinfo($source, PATHINFO_EXTENSION));
            $disk->move($source, $path);
        }

        return [
            'attachment_path' => $path, 'attachment_name' => Str::limit($original, 250, ''), 'attachment_size' => (int) $disk->size($path),
            'attachment_mime' => $disk->mimeType($path) ?: null, 'attachment_sha256' => hash('sha256', (string) $disk->get($path)),
        ];
    }

    /** Temporary signed link; the download route re-authorises the case and the comment visibility. */
    public function url(TicketComment $comment, int $minutes = 15): ?string
    {
        return $comment->attachment_path === null ? null
            : URL::temporarySignedRoute('tickets.attachment', now()->addMinutes($minutes), ['ticket' => $comment->ticket_id, 'comment' => $comment->id]);
    }

    private function assertAllowed(string $name, int $bytes, ?string $sniffed = null): void
    {
        try {
            FileSafety::assertAllowed($name, $bytes, $sniffed);
        } catch (\RuntimeException $e) {
            throw new ServiceDeskRuleViolation($e->getMessage());
        }
    }
}
