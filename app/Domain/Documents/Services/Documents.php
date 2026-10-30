<?php

namespace App\Domain\Documents\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Notifications\Services\NotificationEngine;
use App\Support\Storage\FileSafety;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Document management core (§39, §83): private storage, versions per type, verification,
 * audited access, temporary signed download links.
 */
final class Documents
{
    /** HROne parity MS-2: the most "Download all" puts in one ZIP (sum of the files' sizes). */
    public const BUNDLE_MAX_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
        private readonly NotificationEngine $notifications,
        private readonly NotificationContext $context,
    ) {}

    /**
     * $generatedBySystem is for files PeopleOS itself produced (issued letters); every other file is a
     * user upload and passes the server-side file safety checks.
     */
    public function store(Employee $employee, UploadedFile $file, ?DocumentType $type = null, ?string $title = null, ?string $expiresOn = null, ?string $issuedOn = null, ?string $reason = null, bool $generatedBySystem = false, ?string $uploadComment = null): EmployeeDocument
    {
        $disk = config('peopleos.documents.disk');
        $tenantId = $employee->tenant_id;
        // Production readiness closure: extension allowlist, size limit and content sniffing on the server.
        $extension = $generatedBySystem ? strtolower($file->getClientOriginalExtension())
            : FileSafety::assertAllowed($file->getClientOriginalName(), (int) $file->getSize(), FileSafety::sniffFile((string) $file->getRealPath()) ?? $file->getMimeType());
        $path = $file->storeAs("tenants/{$tenantId}/employees/{$employee->id}", Str::ulid().'.'.$extension, $disk);

        $version = $type
            ? (int) EmployeeDocument::query()->where('employee_id', $employee->id)->where('document_type_id', $type->id)->max('version') + 1
            : 1;

        $document = new EmployeeDocument([
            'employee_id' => $employee->id,
            'document_type_id' => $type?->id,
            'title' => $title ?: ($type?->name ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize() ?: 0,
            'version' => $version,
            'status' => 'pending',
            'issued_on' => $issuedOn,
            'expires_on' => $expiresOn,
            'uploaded_by' => auth()->id(),
            // HROne parity MS-3 (22.2.9 #5): the uploader's own comment (My HR upload, a document attached to a profile change).
            'upload_comment' => filled($uploadComment) ? mb_substr(trim(strip_tags($uploadComment)), 0, 500) : null,
        ]);
        $document->withAuditReason($reason)->save();

        if ($version > 1) {
            EmployeeDocument::query()->where('employee_id', $employee->id)->where('document_type_id', $type->id)
                ->where('version', '<', $version)->where('status', '!=', 'archived')
                ->get()->each(fn (EmployeeDocument $old) => $old->withAuditReason("Superseded by v{$version}")->update(['status' => 'archived']));
        }

        $this->notifications->fire('document.uploaded', $this->context->build($employee, ['document' => $this->vars($document)]), $document);

        return $document;
    }

    public function review(EmployeeDocument $document, bool $verified, ?string $note = null, ?User $actor = null): EmployeeDocument
    {
        $actor ??= auth()->user();
        // Phase 12: separation of duties — whoever uploaded a document does not verify it.
        if ($actor !== null && $document->uploaded_by !== null && (int) $document->uploaded_by === (int) $actor->id) {
            throw new RuntimeException('The person who uploaded a document cannot verify it.');
        }
        $document->loadMissing(['employee', 'type']);
        $document->withAuditReason($note)->update([
            'status' => $verified ? 'verified' : 'rejected',
            'verified_by' => $actor?->id,
            'verified_at' => now(),
            'review_note' => $note,
        ]);

        $this->audit->record($verified ? AuditAction::Approved : AuditAction::Rejected, 'documents', $document, reason: $note, actor: $actor);

        if ($verified) {
            $this->timeline->record($document->employee, 'document', "{$document->title} verified", now(), $note, $document);
        }

        $this->notifications->fire($verified ? 'document.verified' : 'document.rejected', $this->context->build($document->employee, ['document' => $this->vars($document)]), $document);

        return $document;
    }

    /** Authorised, audited access: returns a short-lived signed URL (§83). */
    public function downloadUrl(EmployeeDocument $document, int $minutes = 10): string
    {
        return URL::temporarySignedRoute('documents.download', now()->addMinutes($minutes), ['document' => $document->id]);
    }

    public function recordAccess(EmployeeDocument $document, string $purpose = 'download'): void
    {
        // Phase 14: every download is audited; previews only for sensitive documents.
        if ($purpose !== 'download' && ! $document->isSensitive()) {
            return;
        }

        $this->audit->record($purpose === 'download' ? AuditAction::Download : AuditAction::View, 'documents', $document, metadata: ['purpose' => $purpose, 'employee_id' => $document->employee_id, 'sensitive' => $document->isSensitive()], reason: $purpose);
    }

    /**
     * HROne parity MS-2 ("Download all"): one ZIP of the given documents, built on the fly in a temporary file that the
     * caller sends and removes. Each document is checked again (the viewer may open it, it belongs to $employee and is
     * current) and each file put in the ZIP is one DOWNLOAD audit entry (purpose download_all). Null when nothing can be
     * included; refused above BUNDLE_MAX_BYTES.
     *
     * @param  iterable<EmployeeDocument>  $documents
     * @return array{path: string, count: int}|null
     */
    public function bundle(User $viewer, Employee $employee, iterable $documents): ?array
    {
        $chosen = collect($documents)->filter(fn (EmployeeDocument $d) => (int) $d->employee_id === (int) $employee->getKey() && $d->status !== 'archived' && $viewer->can('view', $d))->values();
        if ($chosen->isEmpty()) {
            return null;
        }
        if ((int) $chosen->sum('size_bytes') > self::BUNDLE_MAX_BYTES) {
            throw new RuntimeException('These documents are too large to download together. Download them one at a time.');
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'peopleos-documents-');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The download could not be prepared.');
        }
        $names = [];
        $count = 0;
        foreach ($chosen as $document) {
            $disk = Storage::disk($document->disk);
            if (! $disk->exists($document->path)) {
                continue;
            }
            $extension = strtolower(pathinfo((string) ($document->original_name ?: $document->path), PATHINFO_EXTENSION));
            $base = trim(preg_replace('~[\\/:*?"<>|\x00-\x1F]+~', '-', (string) $document->title) ?? '', ' .-') ?: 'Document';
            $name = $base.($document->version > 1 ? " (v{$document->version})" : '').($extension !== '' ? '.'.$extension : '');
            for ($i = 2; isset($names[strtolower($name)]); $i++) {
                $name = $base." ({$i})".($extension !== '' ? '.'.$extension : '');
            }
            $names[strtolower($name)] = true;
            // A file on a local disk is read by ZipArchive when the archive is written (not held in memory).
            $local = rescue(fn () => $disk->path($document->path), null, false);
            is_string($local) && is_file($local) ? $zip->addFile($local, $name) : $zip->addFromString($name, (string) $disk->get($document->path));
            $this->audit->record(AuditAction::Download, 'documents', $document, reason: 'download_all',
                metadata: ['purpose' => 'download_all', 'employee_id' => $document->employee_id, 'sensitive' => $document->isSensitive()]);
            $count++;
        }
        $zip->close();
        if ($count === 0) {
            @unlink($path);

            return null;
        }

        return ['path' => $path, 'count' => $count];
    }

    public function delete(EmployeeDocument $document, ?string $reason = null): void
    {
        Storage::disk($document->disk)->delete($document->path);
        $document->withAuditReason($reason)->delete();
    }

    /**
     * Documents expiring within $days, or within their type's own alert days (HROne setup parity CA-4: a document type may
     * set expiry_alert_days; without it the organisation's window applies).
     *
     * @return Collection<int, EmployeeDocument>
     */
    public function expiringWithin(int $days)
    {
        $longest = max($days, (int) DocumentType::query()->max('expiry_alert_days'));

        return EmployeeDocument::query()
            ->with(['employee.person', 'type'])
            ->whereIn('status', ['pending', 'verified'])
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [now()->toDateString(), now()->addDays($longest)->toDateString()])
            ->get()
            ->filter(fn (EmployeeDocument $document) => $document->expires_on->lte(now()->addDays($document->type?->expiry_alert_days ?? $days)->endOfDay()))
            ->values();
    }

    /** @return array<string, mixed> */
    public function vars(EmployeeDocument $document): array
    {
        return ['id' => $document->id, 'title' => $document->title, 'type' => $document->type?->name, 'status' => $document->status, 'expires_on' => $document->expires_on, 'version' => $document->version];
    }
}
